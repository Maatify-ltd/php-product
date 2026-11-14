<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\ImageRole\DTO\ImageRoleDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;
use Maatify\Product\ImageRole\Repository\ImageRoleQueryReaderInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for stored Product Image Roles. It performs no write and holds no lock.
 *
 * Lists sort the role key by its binary value (`CAST(role_key AS BINARY)`), so the order is
 * deterministic even between keys the collation treats as equal; the paginated read exposes that
 * order as the `role_key` sort key through the selected `role_key_binary` alias.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoImageRoleQueryReader implements ImageRoleQueryReaderInterface
{
    private const string TABLE = 'maa_product_image_roles';
    private const string COLUMNS = '`id`, `role_key`, `status`, `created_at`, `updated_at`, `deleted_at`';

    private RowHydrator $row;

    public function __construct(
        private PDO $pdo,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
    }

    public function findById(int $roleId): ?ImageRoleDTO
    {
        return $this->findOne('`id` = :value', $roleId);
    }

    public function findByKey(string $roleKey): ?ImageRoleDTO
    {
        return $this->findOne('`role_key` = :value', $roleKey);
    }

    public function listForManagement(ImageRoleListCriteriaDTO $criteria): array
    {
        [$where, $params] = self::filter($criteria);

        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE ' . $where
            . ' ORDER BY CAST(`role_key` AS BINARY) ASC, `id` ASC LIMIT :list_limit',
            $params,
            $criteria->maxResults,
        ));
    }

    public function paginateForManagement(ImageRoleListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$where, $params] = self::filter($criteria);
        $from = ' FROM `' . self::TABLE . '` WHERE ' . $where;

        return ManagementListQuery::paginate(
            $this->pdo,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT ' . self::COLUMNS . ', CAST(`role_key` AS BINARY) AS `role_key_binary`' . $from,
            $request,
            ManagementListQuery::config(
                ['role_key' => 'role_key_binary', 'id' => 'id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
                'role_key',
            ),
            $this->hydrate(...),
        );
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function filter(ImageRoleListCriteriaDTO $criteria): array
    {
        $where = '1 = 1';
        $params = [];
        if ($criteria->status !== null) {
            $where .= ' AND `status` = :status';
            $params['status'] = $criteria->status->value;
        }

        return [$where . ManagementListQuery::deletedCondition($criteria->deletedState), $params];
    }

    private function findOne(string $condition, int|string $value): ?ImageRoleDTO
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
    private function hydrate(array $row): ImageRoleDTO
    {
        $status = ImageRoleStatusEnum::tryFrom(RowHydrator::string($row, 'status'));
        if ($status === null) {
            throw ProductStorageFailureFactory::invalidStorageValue('status');
        }

        return new ImageRoleDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            roleKey: RowHydrator::string($row, 'role_key'),
            status: $status,
            createdAt: $this->row->timestamp($row, 'created_at'),
            updatedAt: $this->row->timestamp($row, 'updated_at'),
            deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
        );
    }
}
