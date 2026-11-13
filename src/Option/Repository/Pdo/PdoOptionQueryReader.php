<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\Option\Repository\OptionQueryReaderInterface;
use Maatify\Product\Variant\Repository\Pdo\VariantVisibilitySql;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for stored Options. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoOptionQueryReader implements OptionQueryReaderInterface
{
    private const string TABLE = 'maa_product_options';
    private const string COLUMNS = '`id`, `product_id`, `code`, `status`, `display_order`, `created_at`, `updated_at`, `deleted_at`';

    private RowHydrator $row;

    public function __construct(
        private PDO $pdo,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
    }

    public function findById(int $optionId): ?OptionDTO
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE `id` = :id LIMIT 1');
        $statement->execute(['id' => $optionId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForManagement(int $productId, OptionListCriteriaDTO $criteria): array
    {
        [$where, $params] = self::filter($productId, $criteria);

        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE ' . $where . ' ORDER BY `display_order` ASC, `id` ASC LIMIT :list_limit',
            $params,
            $criteria->maxResults,
        ));
    }

    public function paginateForManagement(int $productId, OptionListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$where, $params] = self::filter($productId, $criteria);
        $from = ' FROM `' . self::TABLE . '` WHERE ' . $where;

        return ManagementListQuery::paginate(
            $this->pdo,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT ' . self::COLUMNS . $from,
            $request,
            ManagementListQuery::config(
                ['display_order' => 'display_order', 'id' => 'id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
                'display_order',
            ),
            $this->hydrate(...),
        );
    }

    public function listVisible(int $productId, int $maxResults): array
    {
        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` o WHERE o.`product_id` = :product_id '
            . 'AND o.`deleted_at` IS NULL AND o.`status` = \'active\' '
            . 'AND ' . VariantVisibilitySql::labelled('maa_product_option_contents', 'option_id', 'o') . ' '
            . 'AND EXISTS (SELECT 1 FROM `maa_product_variants` vv WHERE vv.`product_id` = o.`product_id` AND '
            . VariantVisibilitySql::visiblePredicate('vv') . ') '
            . 'ORDER BY o.`display_order` ASC, o.`id` ASC LIMIT :list_limit',
            ['product_id' => $productId],
            $maxResults,
        ));
    }

    /**
     * The owner, status, and deleted-state filter shared by the list, the counts, and the page.
     *
     * @return array{string, array<string, string|int>}
     */
    private static function filter(int $productId, OptionListCriteriaDTO $criteria): array
    {
        $where = '`product_id` = :owner_id';
        $params = ['owner_id' => $productId];
        if ($criteria->status !== null) {
            $where .= ' AND `status` = :status';
            $params['status'] = $criteria->status->value;
        }

        return [$where . ManagementListQuery::deletedCondition($criteria->deletedState), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OptionDTO
    {
        $status = OptionStatusEnum::tryFrom(RowHydrator::string($row, 'status'));
        if ($status === null) {
            throw ProductStorageFailureFactory::invalidStorageValue('status');
        }

        return new OptionDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            productId: RowHydrator::positiveInt($row, 'product_id'),
            code: RowHydrator::string($row, 'code'),
            status: $status,
            displayOrder: RowHydrator::positiveInt($row, 'display_order'),
            createdAt: $this->row->timestamp($row, 'created_at'),
            updatedAt: $this->row->timestamp($row, 'updated_at'),
            deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
        );
    }
}
