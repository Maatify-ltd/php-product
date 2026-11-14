<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\Product\Product\Repository\ProductQueryReaderInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for stored Products. It performs no write and holds no lock.
 *
 * The management search is a literal substring of `code`: `!`, `%`, and `_` are escaped with `!` and
 * the term is matched with `LIKE ... ESCAPE '!'` under the column collation. `!` is used because no
 * `sql_mode` changes its literal (a backslash escape breaks under `NO_BACKSLASH_ESCAPES`), so `\` is
 * an ordinary character. A paginated read counts
 * `total` without the search and `filtered` with it.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoProductQueryReader implements ProductQueryReaderInterface
{
    private const string TABLE = 'maa_product_products';
    private const string COLUMNS = '`id`, `code`, `status`, `created_at`, `updated_at`, `deleted_at`';

    private RowHydrator $row;

    public function __construct(
        private PDO $pdo,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
    }

    public function findById(int $productId): ?ProductDTO
    {
        return $this->findOne('`id` = :value', $productId);
    }

    public function findByCode(string $code): ?ProductDTO
    {
        return $this->findOne('`code` = :value', $code);
    }

    public function listForManagement(ProductListCriteriaDTO $criteria): array
    {
        [$where, $params] = self::filter($criteria);
        [$search, $searchParams] = self::search($criteria);

        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE ' . $where . $search . ' ORDER BY `id` ASC LIMIT :list_limit',
            $params + $searchParams,
            $criteria->maxResults,
        ));
    }

    public function paginateForManagement(ProductListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$where, $params] = self::filter($criteria);
        [$search, $searchParams] = self::search($criteria);
        $from = ' FROM `' . self::TABLE . '` WHERE ' . $where;

        return ManagementListQuery::paginate(
            $this->pdo,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT COUNT(*)' . $from . $search,
            $params + $searchParams,
            'SELECT ' . self::COLUMNS . $from . $search,
            $request,
            ManagementListQuery::config(['id' => 'id', 'code' => 'code', 'created_at' => 'created_at', 'updated_at' => 'updated_at'], 'id'),
            $this->hydrate(...),
        );
    }

    /**
     * The status and deleted-state filter, without the search.
     *
     * @return array{string, array<string, string>}
     */
    private static function filter(ProductListCriteriaDTO $criteria): array
    {
        $where = '1 = 1';
        $params = [];
        if ($criteria->status !== null) {
            $where .= ' AND `status` = :status';
            $params['status'] = $criteria->status->value;
        }

        return [$where . ManagementListQuery::deletedCondition($criteria->deletedState), $params];
    }

    /**
     * The literal substring condition on `code`, or no condition without a search term.
     *
     * @return array{string, array<string, string>}
     */
    private static function search(ProductListCriteriaDTO $criteria): array
    {
        if ($criteria->search === null) {
            return ['', []];
        }
        // The escape character is replaced first, so the escapes added for `%` and `_` stay single.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $criteria->search);

        return [' AND `code` LIKE :search ESCAPE \'!\'', ['search' => '%' . $escaped . '%']];
    }

    private function findOne(string $condition, int|string $value): ?ProductDTO
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE ' . $condition . ' LIMIT 1');
        $statement->execute(['value' => $value]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductDTO
    {
        $status = ProductStatusEnum::tryFrom(RowHydrator::string($row, 'status'));
        if ($status === null) {
            throw ProductStorageFailureFactory::invalidStorageValue('status');
        }

        return new ProductDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            code: RowHydrator::string($row, 'code'),
            status: $status,
            createdAt: $this->row->timestamp($row, 'created_at'),
            updatedAt: $this->row->timestamp($row, 'updated_at'),
            deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
        );
    }
}
