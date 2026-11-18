<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use PDO;
use Throwable;

/**
 * Shared mechanics of the management list reads: the bounded unpaginated fetch with its limit bound
 * as an integer parameter, the soft-deletion filter, and delegation of paginated reads to the shared
 * `maatify/php-persistence` paginator (20 per page by default, 1 to 100, `id` ascending as the
 * tie-breaker). The callers own the filters, the selected columns, and the row mapping.
 *
 * @internal Not part of the Public Runtime API.
 */
final class ManagementListQuery
{
    public const int DEFAULT_PER_PAGE = 20;
    public const int MAX_PER_PAGE = 100;

    private function __construct() {}

    /**
     * The condition for a deleted-state filter on the `deleted_at` column of the given alias prefix.
     */
    public static function deletedCondition(ProductDeletedStateEnum $deletedState, string $prefix = ''): string
    {
        return match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => ' AND ' . $prefix . '`deleted_at` IS NULL',
            ProductDeletedStateEnum::DELETED_ONLY => ' AND ' . $prefix . '`deleted_at` IS NOT NULL',
            ProductDeletedStateEnum::INCLUDE_DELETED => '',
        };
    }

    /**
     * Runs a bounded list query whose SQL ends with `LIMIT :list_limit`, binding every parameter by
     * its type and the limit as an integer.
     *
     * @param array<string, string|int|bool|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchBounded(PDO $pdo, string $sql, array $params, int $limit): array
    {
        $statement = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->bindValue(':list_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * The pagination configuration of a management list.
     *
     * @param non-empty-array<non-empty-string, non-empty-string> $sorts Public sort key => column or select alias.
     */
    public static function config(array $sorts, string $defaultSortBy): PaginationConfig
    {
        return new PaginationConfig(
            sortWhitelist: new SortWhitelist($sorts),
            defaultSortBy: $defaultSortBy,
            defaultSortDirection: SortDirectionEnum::ASC,
            tieBreakerSortBy: 'id',
            tieBreakerDirection: SortDirectionEnum::ASC,
            defaultPerPage: self::DEFAULT_PER_PAGE,
            minPerPage: 1,
            maxPerPage: self::MAX_PER_PAGE,
        );
    }

    /**
     * Delegates one page to the shared paginator.
     *
     * @template T of array<array-key, mixed>|object
     *
     * @param array<string, string|int|bool|null> $totalParams
     * @param array<string, string|int|bool|null> $filteredParams
     * @param callable(array<string, mixed>): T $mapper
     *
     * @return PageResultDTO<T>
     */
    public static function paginate(
        PDO $pdo,
        string $totalSql,
        array $totalParams,
        string $filteredSql,
        array $filteredParams,
        string $dataSql,
        PageRequest $request,
        PaginationConfig $config,
        callable $mapper,
    ): PageResultDTO {
        $read = static fn(): PageResultDTO => (new PdoPaginator())->paginate(
            $pdo,
            new PdoPaginationQueryDescriptor($totalSql, $totalParams, $filteredSql, $filteredParams, $dataSql, $filteredParams),
            $request,
            $config,
            $mapper,
        );

        if ($pdo->inTransaction()) {
            return $read();
        }

        // PDO's autocommit attribute can be stale when the Host changes the session through SQL.
        $session = $pdo->query('SELECT @@SESSION.autocommit');
        if ($session === false) {
            throw new ProductPersistenceException('Could not read the MySQL session autocommit state.');
        }
        $autocommit = $session->fetchColumn();
        $session->closeCursor();

        if ($autocommit === 0 || $autocommit === '0') {
            return $read();
        }
        if ($autocommit !== 1 && $autocommit !== '1') {
            throw new ProductPersistenceException('Could not read the MySQL session autocommit state.');
        }

        // SET TRANSACTION applies to the next transaction only; it does not change the session default.
        if ($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') === false || !$pdo->beginTransaction()) {
            throw new ProductPersistenceException('Could not start a consistent pagination read.');
        }
        try {
            $page = $read();
            if (!$pdo->commit()) {
                throw new ProductPersistenceException('Could not finish a consistent pagination read.');
            }

            return $page;
        } catch (Throwable $failure) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
                // Keep the original query or transaction failure.
            }
            throw $failure;
        }
    }
}
