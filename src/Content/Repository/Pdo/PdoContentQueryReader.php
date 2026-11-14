<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\ContentRecordDTO;
use Maatify\Product\Content\Enum\ContentOwnerEnum;
use Maatify\Product\Content\Repository\ContentQueryReaderInterface;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for the Content records of one owner kind.
 *
 * Language matching is exact: an exact language compares `language_code = :language_code` under
 * the column collation, the unlocalized selector is `language_code IS NULL`, and nothing falls
 * back. Pagination mechanics are delegated to `maatify/php-persistence` (`PdoPaginator`): 20 records
 * per page by default, 1 to 100, sortable by `language_code` (default, ascending), `id`,
 * `created_at`, or `updated_at`, with `id ASC` as the tie-breaker.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoContentQueryReader implements ContentQueryReaderInterface
{
    private RowHydrator $row;
    private PaginationConfig $pagination;

    public function __construct(
        private PDO $pdo,
        private ContentOwnerEnum $owner,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
        $this->pagination = new PaginationConfig(
            sortWhitelist: new SortWhitelist([
                'language_code' => 'language_code',
                'id' => 'id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ]),
            defaultSortBy: 'language_code',
            defaultSortDirection: SortDirectionEnum::ASC,
            tieBreakerSortBy: 'id',
            tieBreakerDirection: SortDirectionEnum::ASC,
            defaultPerPage: 20,
            minPerPage: 1,
            maxPerPage: 100,
        );
    }

    public function findById(int $contentId): ?ContentRecordDTO
    {
        $statement = $this->pdo->prepare('SELECT ' . $this->columns('') . ' FROM `' . $this->owner->table() . '` WHERE `id` = :id LIMIT 1');
        $statement->execute(['id' => $contentId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForManagement(
        int $ownerId,
        ContentLanguageFilter $language,
        ProductDeletedStateEnum $deletedState,
        int $maxResults,
    ): array {
        [$where, $params] = $this->managementFilter($ownerId, $language, $deletedState);
        return $this->hydrateAll(ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . $this->columns('') . ' FROM `' . $this->owner->table() . '` WHERE ' . $where
            . ' ORDER BY `language_code` ASC, `id` ASC LIMIT :list_limit',
            $params,
            $maxResults,
        ));
    }

    public function paginateForManagement(
        int $ownerId,
        ContentLanguageFilter $language,
        ProductDeletedStateEnum $deletedState,
        PageRequest $request,
        callable $mapper,
    ): PageResultDTO {
        [$where, $params] = $this->managementFilter($ownerId, $language, $deletedState);
        $from = ' FROM `' . $this->owner->table() . '` WHERE ' . $where;
        $count = 'SELECT COUNT(*)' . $from;

        return (new PdoPaginator())->paginate(
            $this->pdo,
            new PdoPaginationQueryDescriptor($count, $params, $count, $params, 'SELECT ' . $this->columns('') . $from, $params),
            $request,
            $this->pagination,
            fn(array $row): object => $mapper($this->hydrate($row)),
        );
    }

    public function listVisible(int $ownerId, ExactContentLanguage $language): array
    {
        $chain = match ($this->owner) {
            ContentOwnerEnum::PRODUCT => 'JOIN `maa_product_products` p ON p.`id` = c.`product_id`',
            ContentOwnerEnum::OPTION => 'JOIN `maa_product_options` o ON o.`id` = c.`option_id` '
                . 'JOIN `maa_product_products` p ON p.`id` = o.`product_id`',
            ContentOwnerEnum::OPTION_VALUE => 'JOIN `maa_product_option_values` ov ON ov.`id` = c.`option_value_id` '
                . 'JOIN `maa_product_options` o ON o.`id` = ov.`option_id` '
                . 'JOIN `maa_product_products` p ON p.`id` = o.`product_id`',
        };
        $visible = match ($this->owner) {
            ContentOwnerEnum::PRODUCT => '',
            ContentOwnerEnum::OPTION => ' AND o.`status` = \'active\' AND o.`deleted_at` IS NULL',
            ContentOwnerEnum::OPTION_VALUE => ' AND o.`status` = \'active\' AND o.`deleted_at` IS NULL'
                . ' AND ov.`status` = \'active\' AND ov.`deleted_at` IS NULL',
        };
        $params = ['owner_id' => $ownerId];
        $languageCode = $language->languageCode();
        if ($languageCode === null) {
            $languageMatch = 'c.`language_code` IS NULL';
        } else {
            $languageMatch = 'c.`language_code` = :language_code';
            $params['language_code'] = $languageCode;
        }
        $statement = $this->pdo->prepare(
            'SELECT ' . $this->columns('c.') . ' FROM `' . $this->owner->table() . '` c ' . $chain
            . ' WHERE c.`' . $this->owner->ownerColumn() . '` = :owner_id AND ' . $languageMatch . ' AND c.`deleted_at` IS NULL'
            . ' AND p.`status` = \'active\' AND p.`deleted_at` IS NULL' . $visible
            . ' ORDER BY c.`id` ASC LIMIT 1',
        );
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    /**
     * The owner, language, and deleted-state filter shared by the list, the count, and the page queries.
     *
     * @return array{string, array<string, string|int>}
     */
    private function managementFilter(int $ownerId, ContentLanguageFilter $language, ProductDeletedStateEnum $deletedState): array
    {
        $where = '`' . $this->owner->ownerColumn() . '` = :owner_id';
        $params = ['owner_id' => $ownerId];
        $languageCode = $language->languageCode();
        if ($language->isExactLanguage() && $languageCode !== null) {
            $where .= ' AND `language_code` = :language_code';
            $params['language_code'] = $languageCode;
        } elseif ($language->isUnlocalizedOnly()) {
            $where .= ' AND `language_code` IS NULL';
        }
        $where .= match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => ' AND `deleted_at` IS NULL',
            ProductDeletedStateEnum::DELETED_ONLY => ' AND `deleted_at` IS NOT NULL',
            ProductDeletedStateEnum::INCLUDE_DELETED => '',
        };

        return [$where, $params];
    }

    private function columns(string $prefix): string
    {
        $columns = ['id', $this->owner->ownerColumn(), 'language_code', 'name'];
        if ($this->owner->hasDescriptions()) {
            $columns[] = 'short_description';
            $columns[] = 'description';
        }
        $columns = [...$columns, 'created_at', 'updated_at', 'deleted_at'];

        return implode(', ', array_map(static fn(string $column): string => $prefix . '`' . $column . '`', $columns));
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<ContentRecordDTO>
     */
    private function hydrateAll(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $records[] = $this->hydrate($row);
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ContentRecordDTO
    {
        return new ContentRecordDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            ownerId: RowHydrator::positiveInt($row, $this->owner->ownerColumn()),
            languageCode: RowHydrator::nullableString($row, 'language_code'),
            fields: new ContentFieldsDTO(
                RowHydrator::string($row, 'name'),
                $this->owner->hasDescriptions() ? RowHydrator::nullableString($row, 'short_description') : null,
                $this->owner->hasDescriptions() ? RowHydrator::nullableString($row, 'description') : null,
            ),
            createdAt: $this->row->timestamp($row, 'created_at'),
            updatedAt: $this->row->timestamp($row, 'updated_at'),
            deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
        );
    }
}
