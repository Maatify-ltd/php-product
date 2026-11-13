<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\DTO\VariantCompositionPairDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
use Maatify\Product\Variant\Repository\VariantQueryReaderInterface;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for stored Variants on MySQL 8.0.16 or later.
 *
 * It performs no write and holds no lock. The consumer-visibility predicate lives in SQL, in
 * {@see VariantVisibilitySql}, so that a list is bounded and filtered by the database.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoVariantQueryReader implements VariantQueryReaderInterface
{
    private const string COLUMNS = 'v.`id`, v.`product_id`, v.`sku`, v.`barcode`, v.`status`, v.`display_order`, '
        . 'v.`created_at`, v.`updated_at`, v.`deleted_at`';

    private RowHydrator $row;

    public function __construct(
        private PDO $pdo,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
    }

    public function findById(int $variantId): ?VariantDTO
    {
        $variants = $this->fetch(
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE v.`id` = :variant_id LIMIT 1',
            ['variant_id' => $variantId],
        );

        return $variants[0] ?? null;
    }

    public function findBySku(string $sku): ?VariantDTO
    {
        $variants = $this->fetch(
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE v.`sku` = :identifier LIMIT 1',
            ['identifier' => $sku],
        );

        return $variants[0] ?? null;
    }

    public function findByBarcode(string $barcode): ?VariantDTO
    {
        $variants = $this->fetch(
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE v.`barcode` = :identifier LIMIT 1',
            ['identifier' => $barcode],
        );

        return $variants[0] ?? null;
    }

    public function findVisibleById(int $variantId): ?VariantDTO
    {
        $variants = $this->fetch(
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE '
            . 'v.`id` = :variant_id AND ' . VariantVisibilitySql::visiblePredicate('v') . ' LIMIT 1',
            ['variant_id' => $variantId],
        );

        return $variants[0] ?? null;
    }

    public function listForManagement(int $productId, VariantListCriteriaDTO $criteria): array
    {
        [$where, $params] = self::managementFilter($productId, $criteria);

        return $this->hydrate(ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE ' . $where
            . ' ORDER BY v.`display_order` ASC, v.`id` ASC LIMIT :list_limit',
            $params,
            $criteria->maxResults,
        ));
    }

    public function paginateForManagement(int $productId, VariantListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$where, $params] = self::managementFilter($productId, $criteria);
        $count = 'SELECT COUNT(*) FROM `maa_product_variants` v WHERE ' . $where;
        $page = ManagementListQuery::paginate(
            $this->pdo,
            $count,
            $params,
            $count,
            $params,
            'SELECT ' . self::COLUMNS . ' FROM `maa_product_variants` v WHERE ' . $where,
            $request,
            ManagementListQuery::config(
                ['display_order' => 'v.display_order', 'id' => 'v.id', 'created_at' => 'v.created_at', 'updated_at' => 'v.updated_at'],
                'display_order',
            ),
            static fn(array $row): array => $row,
        );

        return $this->hydratePage($page);
    }

    public function listVisible(int $productId, VariantVisibleListCriteriaDTO $criteria): array
    {
        return $this->hydrate(ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . self::visibleFrom() . ' ORDER BY v.`display_order` ASC, v.`id` ASC LIMIT :list_limit',
            ['product_id' => $productId],
            $criteria->maxResults,
        ));
    }

    public function paginateVisible(int $productId, PageRequest $request): PageResultDTO
    {
        $from = self::visibleFrom();
        $params = ['product_id' => $productId];
        $count = 'SELECT COUNT(*)' . $from;

        $page = ManagementListQuery::paginate(
            $this->pdo,
            $count,
            $params,
            $count,
            $params,
            'SELECT ' . self::COLUMNS . $from,
            new PageRequest($request->page, $request->perPage),
            ManagementListQuery::config(['display_order' => 'v.display_order', 'id' => 'v.id'], 'display_order'),
            static fn(array $row): array => $row,
        );

        return $this->hydratePage($page);
    }

    private static function visibleFrom(): string
    {
        return ' FROM `maa_product_variants` v WHERE '
            . 'v.`product_id` = :product_id AND ' . VariantVisibilitySql::visiblePredicate('v');
    }

    /**
     * @param PageResultDTO<array<string, mixed>> $page
     * @return PageResultDTO<VariantDTO>
     */
    private function hydratePage(PageResultDTO $page): PageResultDTO
    {
        // The rows are hydrated together so the composition pairs of the page load in one query.
        return new PageResultDTO(
            $this->hydrate($page->data),
            $page->page,
            $page->perPage,
            $page->total,
            $page->filtered,
            $page->totalPages,
            $page->hasNext,
            $page->hasPrevious,
            $page->sortBy,
            $page->sortDirection,
        );
    }


    /**
     * The management filter shared by the list, the counts, and the page.
     *
     * @return array{string, array<string, string|int>}
     */
    private static function managementFilter(int $productId, VariantListCriteriaDTO $criteria): array
    {
        $where = 'v.`product_id` = :product_id';
        $params = ['product_id' => $productId];
        if ($criteria->status !== null) {
            $where .= ' AND v.`status` = :status';
            $params['status'] = $criteria->status->value;
        }

        return [$where . ManagementListQuery::deletedCondition($criteria->deletedState, 'v.'), $params];
    }

    public function isProductVisible(int $productId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `maa_product_products` WHERE `id` = :id AND `deleted_at` IS NULL AND `status` = \'active\' LIMIT 1',
        );
        $statement->execute(['id' => $productId]);

        return $statement->fetchColumn() !== false;
    }

    public function listActiveOptionIds(int $productId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT `id` FROM `maa_product_options` WHERE `product_id` = :product_id "
            . "AND `status` = 'active' AND `deleted_at` IS NULL ORDER BY `id` ASC",
        );
        $statement->execute(['product_id' => $productId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): int => RowHydrator::positiveInt($row, 'id'), $rows);
    }

    public function findActiveValueOptionIds(int $productId, array $valueIds): array
    {
        $owners = [];
        // Keep each statement below MySQL's parameter limit without imposing a new selection limit.
        foreach (array_chunk(array_values(array_unique($valueIds)), 1000) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $statement = $this->pdo->prepare(
                'SELECT ov.`id`, ov.`option_id` FROM `maa_product_option_values` ov '
                . 'JOIN `maa_product_options` o ON o.`id` = ov.`option_id` '
                . 'WHERE o.`product_id` = ? AND ov.`id` IN (' . $placeholders . ') '
                . "AND ov.`status` = 'active' AND ov.`deleted_at` IS NULL",
            );
            $statement->execute([$productId, ...$batch]);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $owners[RowHydrator::positiveInt($row, 'id')] = RowHydrator::positiveInt($row, 'option_id');
            }
        }

        return $owners;
    }

    public function findActiveByFingerprint(int $productId, string $fingerprint): ?VariantStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `composition_fingerprint`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` '
            . 'FROM `maa_product_variants` WHERE `product_id` = :product_id AND `composition_fingerprint` = :fingerprint '
            . 'AND `deleted_at` IS NULL AND `status` = \'active\' LIMIT 1',
        );
        $statement->execute(['product_id' => $productId, 'fingerprint' => $fingerprint]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $id = RowHydrator::positiveInt($row, 'id');
        $pairs = $this->compositions([$id])[$id] ?? [];

        return new VariantStateDTO(
            id: $id,
            productId: RowHydrator::positiveInt($row, 'product_id'),
            fingerprint: RowHydrator::string($row, 'composition_fingerprint'),
            active: RowHydrator::isActive($row),
            deleted: RowHydrator::flag($row, 'deleted'),
            composition: array_map(
                static fn(VariantCompositionPairDTO $pair): OptionValueSelection => new OptionValueSelection($pair->optionId, $pair->optionValueId),
                $pairs,
            ),
        );
    }

    /**
     * @param array<string, int|string> $params
     *
     * @return list<VariantDTO>
     */
    private function fetch(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrate($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<VariantDTO>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $compositions = $this->compositions(array_map(
            static fn(array $row): int => RowHydrator::positiveInt($row, 'id'),
            $rows,
        ));

        $variants = [];
        foreach ($rows as $row) {
            $id = RowHydrator::positiveInt($row, 'id');
            $status = VariantStatusEnum::tryFrom(RowHydrator::string($row, 'status'));
            if ($status === null) {
                throw ProductStorageFailureFactory::invalidStorageValue('status');
            }
            $displayOrder = $row['display_order'] ?? null;
            if (!is_int($displayOrder) && !is_string($displayOrder)) {
                throw ProductStorageFailureFactory::unexpectedColumnType('display_order');
            }

            $variants[] = new VariantDTO(
                id: $id,
                productId: RowHydrator::positiveInt($row, 'product_id'),
                sku: RowHydrator::nullableString($row, 'sku'),
                barcode: RowHydrator::nullableString($row, 'barcode'),
                status: $status,
                displayOrder: (int) $displayOrder,
                composition: $compositions[$id] ?? [],
                createdAt: $this->row->timestamp($row, 'created_at'),
                updatedAt: $this->row->timestamp($row, 'updated_at'),
                deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
            );
        }

        return $variants;
    }

    /**
     * @param non-empty-list<positive-int> $variantIds
     *
     * @return array<int, list<VariantCompositionPairDTO>> Pairs per Variant, ordered by numeric Option identity.
     */
    private function compositions(array $variantIds): array
    {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT `variant_id`, `option_id`, `option_value_id` FROM `maa_product_variant_option_values` WHERE '
            . '`variant_id` IN (' . $placeholders . ') ORDER BY `variant_id` ASC, `option_id` ASC',
        );
        $statement->execute($variantIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[RowHydrator::positiveInt($row, 'variant_id')][] = new VariantCompositionPairDTO(
                RowHydrator::positiveInt($row, 'option_id'),
                RowHydrator::positiveInt($row, 'option_value_id'),
            );
        }

        return $pairs;
    }
}
