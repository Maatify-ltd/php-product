<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Repository\Pdo;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Repository\Pdo\ManagementListQuery;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentQueryReaderInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * PDO read adapter for stored Image Assignments. It performs no write and holds no lock.
 *
 * Every dimension filter is exact: `NULL` matches only `IS NULL`, a value compares under the column
 * collation, and an omitted selector adds no condition. Lists are ordered by exact scope
 * (`ordering_scope`), then `display_order`, then `id`; the paginated read exposes that order as the
 * derived `business_order` key, `ROW_NUMBER() OVER (ORDER BY ordering_scope, display_order, id)`
 * over the same filtered rows, because the shared paginator sorts by one key plus the tie-breaker.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoImageAssignmentQueryReader implements ImageAssignmentQueryReaderInterface
{
    private const string TABLE = 'maa_product_image_assignments';
    private const string COLUMNS = '`id`, `product_id`, `variant_id`, `media_asset_id`, `role_id`, `language_code`, `platform`, `is_default`, '
        . '`display_order`, `created_at`, `updated_at`, `deleted_at`';

    private RowHydrator $row;

    public function __construct(
        private PDO $pdo,
        ClockInterface $clock,
    ) {
        $this->row = new RowHydrator($clock);
    }

    public function findById(int $assignmentId): ?ImageAssignmentDTO
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE `id` = :id LIMIT 1');
        $statement->execute(['id' => $assignmentId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForManagement(ImageAssignmentListCriteriaDTO $criteria): array
    {
        [$where, $params] = self::filter($criteria);

        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE ' . $where
            . ' ORDER BY `ordering_scope` ASC, `display_order` ASC, `id` ASC LIMIT :list_limit',
            $params,
            $criteria->maxResults,
        ));
    }

    public function paginateForManagement(ImageAssignmentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$where, $params] = self::filter($criteria);
        $from = ' FROM `' . self::TABLE . '` WHERE ' . $where;

        return ManagementListQuery::paginate(
            $this->pdo,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT COUNT(*)' . $from,
            $params,
            'SELECT ' . self::COLUMNS . ', ROW_NUMBER() OVER (ORDER BY `ordering_scope` ASC, `display_order` ASC, `id` ASC) AS `business_order`' . $from,
            $request,
            ManagementListQuery::config(
                ['business_order' => 'business_order', 'id' => 'id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
                'business_order',
            ),
            $this->hydrate(...),
        );
    }

    public function listVisibleByExactScope(ImageAssignmentExactScopeCriteriaDTO $criteria): array
    {
        [$from, $params] = self::visibleFrom($criteria);

        return array_map($this->hydrate(...), ManagementListQuery::fetchBounded(
            $this->pdo,
            'SELECT ' . self::COLUMNS . $from . ' ORDER BY a.`display_order` ASC, a.`id` ASC LIMIT :list_limit',
            $params,
            $criteria->maxResults,
        ));
    }

    public function paginateVisibleByExactScope(ImageAssignmentExactScopeCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        [$from, $params] = self::visibleFrom($criteria);
        $count = 'SELECT COUNT(*)' . $from;

        return ManagementListQuery::paginate(
            $this->pdo,
            $count,
            $params,
            $count,
            $params,
            'SELECT ' . self::COLUMNS . $from,
            new PageRequest($request->page, $request->perPage),
            ManagementListQuery::config(['display_order' => 'a.display_order', 'id' => 'a.id'], 'display_order'),
            $this->hydrate(...),
        );
    }

    /** @return array{string, array<string, string|int>} */
    private static function visibleFrom(ImageAssignmentExactScopeCriteriaDTO $criteria): array
    {
        $where = 'a.`product_id` = :product_id AND a.`deleted_at` IS NULL '
            . 'AND EXISTS (SELECT 1 FROM `maa_product_products` p WHERE p.`id` = a.`product_id` AND p.`deleted_at` IS NULL AND p.`status` = \'active\')';
        $params = ['product_id' => $criteria->productId];
        if ($criteria->variantId === null) {
            $where .= ' AND a.`variant_id` IS NULL';
        } else {
            $where .= ' AND a.`variant_id` = :variant_id AND EXISTS (SELECT 1 FROM `maa_product_variants` v WHERE v.`id` = a.`variant_id` '
                . 'AND v.`product_id` = a.`product_id` AND v.`deleted_at` IS NULL AND v.`status` = \'active\')';
            $params['variant_id'] = $criteria->variantId;
        }
        if ($criteria->roleId === null) {
            $where .= ' AND a.`role_id` IS NULL';
        } else {
            $where .= ' AND a.`role_id` = :role_id AND EXISTS (SELECT 1 FROM `maa_product_image_roles` r WHERE r.`id` = a.`role_id` '
                . 'AND r.`deleted_at` IS NULL AND r.`status` = \'active\')';
            $params['role_id'] = $criteria->roleId;
        }
        foreach (['language_code' => $criteria->languageCode, 'platform' => $criteria->platform] as $column => $value) {
            if ($value === null) {
                $where .= ' AND a.`' . $column . '` IS NULL';
            } else {
                $where .= ' AND a.`' . $column . '` = :' . $column;
                $params[$column] = $value;
            }
        }


        return [' FROM `' . self::TABLE . '` a WHERE ' . $where, $params];
    }


    /**
     * The Product, target, role, language, platform, and deleted-state filter shared by the list, the
     * counts, and the page.
     *
     * @return array{string, array<string, string|int>}
     */
    private static function filter(ImageAssignmentListCriteriaDTO $criteria): array
    {
        $where = '`product_id` = :product_id';
        $params = ['product_id' => $criteria->productId];

        $variantId = $criteria->target->variantId();
        if ($criteria->target->isProductLevel()) {
            $where .= ' AND `variant_id` IS NULL';
        } elseif ($variantId !== null) {
            $where .= ' AND `variant_id` = :variant_id';
            $params['variant_id'] = $variantId;
        }

        $roleId = $criteria->role->roleId();
        if ($criteria->role->isExactNull()) {
            $where .= ' AND `role_id` IS NULL';
        } elseif ($roleId !== null) {
            $where .= ' AND `role_id` = :role_id';
            $params['role_id'] = $roleId;
        }

        foreach ([
            'language_code' => [$criteria->language->isExactNull(), $criteria->language->value()],
            'platform' => [$criteria->platform->isExactNull(), $criteria->platform->value()],
        ] as $column => [$exactNull, $value]) {
            if ($exactNull) {
                $where .= ' AND `' . $column . '` IS NULL';
            } elseif ($value !== null) {
                $where .= ' AND `' . $column . '` = :' . $column;
                $params[$column] = $value;
            }
        }

        return [$where . ManagementListQuery::deletedCondition($criteria->deletedState), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ImageAssignmentDTO
    {
        return new ImageAssignmentDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            productId: RowHydrator::positiveInt($row, 'product_id'),
            variantId: RowHydrator::nullablePositiveInt($row, 'variant_id'),
            mediaAssetId: RowHydrator::positiveInt($row, 'media_asset_id'),
            roleId: RowHydrator::nullablePositiveInt($row, 'role_id'),
            languageCode: RowHydrator::nullableString($row, 'language_code'),
            platform: RowHydrator::nullableString($row, 'platform'),
            isDefault: RowHydrator::flag($row, 'is_default'),
            displayOrder: RowHydrator::positiveInt($row, 'display_order'),
            createdAt: $this->row->timestamp($row, 'created_at'),
            updatedAt: $this->row->timestamp($row, 'updated_at'),
            deletedAt: $this->row->nullableTimestamp($row, 'deleted_at'),
        );
    }
}
