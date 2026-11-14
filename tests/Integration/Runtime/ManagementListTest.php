<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentLanguageFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentPlatformFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentRoleFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentTargetFilter;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;

/**
 * Management lists, search, pagination, business-key reads, and the exact image-assignment
 * criteria against real MySQL: status and deleted-state filters,
 * the default order, the `maxResults` bound, the shared paginator's page size, sort whitelist,
 * and aligned counts, and the literal Product code search.
 */
final class ManagementListTest extends RuntimeTestCase
{
    public function testProductsAreListedSearchedAndPaginated(): void
    {
        $service = $this->productManagementService();
        $ids = [];
        foreach (['ALPHA', 'beta', 'A%B', 'A_B', 'A\\B', 'A!B', 'GAMMA'] as $code) {
            $ids[$code] = $service->create(new CreateProductCommand($code))->productId;
        }
        $this->nameProduct($ids['GAMMA'], 'Gamma');
        $this->activateProduct($ids['GAMMA']);
        $service->softDelete($ids['beta']);
        $codes = fn(ProductListCriteriaDTO $criteria): array => array_map(static fn($p): string => $p->code, $service->listForManagement($criteria)->items());

        self::assertSame(['ALPHA', 'A%B', 'A_B', 'A\\B', 'A!B', 'GAMMA'], $codes(new ProductListCriteriaDTO()), 'Non-deleted, any status, by identity.');
        self::assertSame(['GAMMA'], $codes(new ProductListCriteriaDTO(ProductStatusEnum::ACTIVE)));
        self::assertSame(['beta'], $codes(new ProductListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY)));
        self::assertCount(7, $codes(new ProductListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED)));
        self::assertSame(['ALPHA', 'A%B'], $codes(new ProductListCriteriaDTO(null, ProductDeletedStateEnum::NON_DELETED, 2)), 'maxResults bounds the list.');

        self::assertSame(['A%B'], $codes(new ProductListCriteriaDTO(search: '%')), '% is literal.');
        self::assertSame(['A_B'], $codes(new ProductListCriteriaDTO(search: 'A_')), '_ is literal.');
        self::assertSame(['A\\B'], $codes(new ProductListCriteriaDTO(search: 'A\\')), 'A backslash is literal.');
        self::assertSame(['A!B'], $codes(new ProductListCriteriaDTO(search: '!')), 'The escape character itself is literal.');

        // The Host owns sql_mode: under NO_BACKSLASH_ESCAPES the search still treats every character literally.
        $mode = self::scalar('SELECT @@SESSION.sql_mode');
        self::$pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')");
        try {
            self::assertStringContainsString('NO_BACKSLASH_ESCAPES', (string) self::scalar('SELECT @@SESSION.sql_mode'));
            foreach (['%' => ['A%B'], 'A_' => ['A_B'], 'A\\' => ['A\\B'], '!' => ['A!B'], 'b' => ['A%B', 'A_B', 'A\\B', 'A!B']] as $term => $expected) {
                self::assertSame($expected, $codes(new ProductListCriteriaDTO(search: (string) $term)), 'Literal "' . $term . '" under NO_BACKSLASH_ESCAPES.');
            }
            self::assertSame(4, $service->paginateForManagement(new ProductListCriteriaDTO(search: 'b'), new PageRequest())->filtered);
        } finally {
            self::$pdo->prepare('SET SESSION sql_mode = :mode')->execute(['mode' => $mode]);
        }
        self::assertStringNotContainsString('NO_BACKSLASH_ESCAPES', (string) self::scalar('SELECT @@SESSION.sql_mode'));
        self::assertSame(['ALPHA'], $codes(new ProductListCriteriaDTO(search: '  lph ')), 'A substring, trimmed, compared under the collation.');
        self::assertSame(['beta'], $codes(new ProductListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY, 100, 'ET')));

        $page = $service->paginateForManagement(new ProductListCriteriaDTO(search: 'b'), new PageRequest(1, 2));
        self::assertSame([6, 4, 2, 2, 'id'], [$page->total, $page->filtered, $page->perPage, $page->totalPages, $page->sortBy], 'total ignores the search; filtered applies it.');
        self::assertSame(['A%B', 'A_B'], array_map(static fn($p): string => $p->code, $page->data));
        self::assertSame(['A\\B', 'A!B'], array_map(static fn($p): string => $p->code, $service->paginateForManagement(new ProductListCriteriaDTO(search: 'b'), new PageRequest(2, 2))->data));
        $all = $service->paginateForManagement(new ProductListCriteriaDTO(), new PageRequest(1, 1000, 'code', 'DESC'));
        self::assertSame([100, 6, 6], [$all->perPage, $all->total, $all->filtered], 'A page above 100 is clamped and the counts match.');
        self::assertSame('GAMMA', $all->data[0]->code);
        self::assertSame('id', $service->paginateForManagement(new ProductListCriteriaDTO(), new PageRequest(1, 10, 'status'))->sortBy, 'An unknown sort key falls back to the default.');

        self::assertSame($ids['ALPHA'], $service->getByCodeForManagement('alpha')->id, 'The code compares under the collation.');
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->getByCodeForManagement('beta'));
        self::assertSame($ids['beta'], $service->getByCodeForManagement('BETA', ProductDeletedStateEnum::DELETED_ONLY)->id);
        self::assertSame($ids['beta'], $service->getByCodeForManagement('beta', ProductDeletedStateEnum::INCLUDE_DELETED)->id);
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->getByCodeForManagement('ALPHA', ProductDeletedStateEnum::DELETED_ONLY));
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->getByCodeForManagement('missing'));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $service->getByCodeForManagement(' '));
    }

    public function testVariantsArePaginatedWithTheListFilters(): void
    {
        $p = $this->namedProduct('P');
        $color = $this->preparedOption($p->productId, 'color', 'Color');
        $values = [];
        foreach (['red', 'green', 'blue'] as $code) {
            $values[] = $this->preparedValue($color, $code, ucfirst($code));
        }
        $this->productManagementService()->reconfigure($this->reconfiguration($p->productId, [$color => $values], array_map(static fn(int $v): array => [[[$color, $v]]], $values)));
        $service = $this->variantManagementService();

        $first = $service->paginateByProductIdForManagement($p->productId, new VariantListCriteriaDTO(), new PageRequest(1, 3));
        self::assertSame([4, 4, 2, 'display_order'], [$first->total, $first->filtered, $first->totalPages, $first->sortBy]);
        self::assertSame([$p->variantId], [$first->data[0]->id], 'Ordered by display order: the original Variant first.');
        self::assertSame([], $first->data[0]->composition);
        $second = $service->paginateByProductIdForManagement($p->productId, new VariantListCriteriaDTO(), new PageRequest(2, 3));
        self::assertCount(1, $second->data);
        self::assertCount(1, $second->data[0]->composition, 'A page carries each Variant composition.');
        $active = $service->paginateByProductIdForManagement($p->productId, new VariantListCriteriaDTO(VariantStatusEnum::ACTIVE), new PageRequest(1, 100, 'id', 'DESC'));
        self::assertSame(3, $active->filtered);
        self::assertGreaterThan($active->data[1]->id, $active->data[0]->id);
        self::assertSame(0, $service->paginateByProductIdForManagement(999999, new VariantListCriteriaDTO(), new PageRequest())->total, 'A missing Product yields an empty page.');
        self::assertCount(2, $service->listByProductIdForManagement($p->productId, new VariantListCriteriaDTO(maxResults: 2)), 'The bound limit still applies.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $service->paginateByProductIdForManagement(0, new VariantListCriteriaDTO(), new PageRequest()));
    }

    public function testOptionsAndValuesAreListedAndPaginatedPerOwner(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        $options = $this->optionManagementService();
        $values = $this->optionValueManagementService();
        [$a, $b, $c] = array_map(fn(string $code): int => $options->create(new CreateOptionCommand($p->productId, $code))->optionId, ['a', 'b', 'c']);
        $options->create(new CreateOptionCommand($q->productId, 'other'));
        $options->softDelete($b);
        [$x, $y] = array_map(fn(string $code): int => $values->create(new CreateOptionValueCommand($a, $code))->optionValueId, ['x', 'y']);
        $values->create(new CreateOptionValueCommand($c, 'z'));

        $optionIds = fn(OptionListCriteriaDTO $criteria): array => array_map(static fn($o): int => $o->id, $options->listByProductIdForManagement($p->productId, $criteria)->items());
        self::assertSame([$a, $c], $optionIds(new OptionListCriteriaDTO()), 'Non-deleted Options of the Product only, by display order.');
        self::assertSame([$a, $c], $optionIds(new OptionListCriteriaDTO(OptionStatusEnum::INACTIVE)));
        self::assertSame([], $optionIds(new OptionListCriteriaDTO(OptionStatusEnum::ACTIVE)));
        self::assertSame([$b], $optionIds(new OptionListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY)));
        self::assertSame([$a, $b, $c], $optionIds(new OptionListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED)));
        self::assertSame([$a], $optionIds(new OptionListCriteriaDTO(maxResults: 1)));
        self::assertTrue($options->listByProductIdForManagement(999999)->isEmpty(), 'A missing Product yields an empty collection.');
        $page = $options->paginateByProductIdForManagement($p->productId, new OptionListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED), new PageRequest(1, 2, 'id', 'DESC'));
        self::assertSame([3, 3, 2, [$c, $b]], [$page->total, $page->filtered, $page->totalPages, array_map(static fn($o): int => $o->id, $page->data)]);
        self::assertSame('display_order', $options->paginateByProductIdForManagement($p->productId, new OptionListCriteriaDTO(), new PageRequest(sortBy: 'code'))->sortBy, 'code is not a sort key here.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $options->listByProductIdForManagement(0));

        $valueIds = fn(OptionValueListCriteriaDTO $criteria): array => array_map(static fn($v): int => $v->id, $values->listByOptionIdForManagement($a, $criteria)->items());
        $values->softDelete($y);
        self::assertSame([$x], $valueIds(new OptionValueListCriteriaDTO()), 'Values of the Option only.');
        self::assertSame([$x], $valueIds(new OptionValueListCriteriaDTO(OptionValueStatusEnum::INACTIVE)));
        self::assertSame([$y], $valueIds(new OptionValueListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY)));
        self::assertSame([$x, $y], $valueIds(new OptionValueListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED)));
        self::assertTrue($values->listByOptionIdForManagement(999999)->isEmpty());
        $valuePage = $values->paginateByOptionIdForManagement($a, new OptionValueListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED), new PageRequest(2, 1));
        self::assertSame([2, 2, 2, [$y]], [$valuePage->total, $valuePage->filtered, $valuePage->page, array_map(static fn($v): int => $v->id, $valuePage->data)]);
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $values->paginateByOptionIdForManagement(-1, new OptionValueListCriteriaDTO(), new PageRequest()));
    }

    public function testRolesAreListedByBinaryKeyAndFoundByKey(): void
    {
        $roles = $this->imageRoleManagementService();
        $ids = [];
        foreach (['a', 'Z', 'm', 'gone'] as $key) {
            $ids[$key] = $roles->create(new CreateImageRoleCommand($key))->roleId;
        }
        $roles->updateStatus($ids['m'], ImageRoleStatusEnum::INACTIVE);
        $roles->softDelete($ids['gone']);
        $keys = fn(ImageRoleListCriteriaDTO $criteria): array => array_map(static fn($r): string => $r->roleKey, $roles->listForManagement($criteria)->items());

        self::assertSame(['Z', 'a', 'm'], $keys(new ImageRoleListCriteriaDTO()), 'Binary order of the key, not the collation order.');
        self::assertSame(['m'], $keys(new ImageRoleListCriteriaDTO(ImageRoleStatusEnum::INACTIVE)));
        self::assertSame(['gone'], $keys(new ImageRoleListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY)));
        self::assertSame(['Z'], $keys(new ImageRoleListCriteriaDTO(maxResults: 1)));
        $page = $roles->paginateForManagement(new ImageRoleListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED), new PageRequest(1, 3));
        self::assertSame([4, 4, 2, 'role_key', ['Z', 'a', 'gone']], [$page->total, $page->filtered, $page->totalPages, $page->sortBy, array_map(static fn($r): string => $r->roleKey, $page->data)]);
        self::assertSame(['gone', 'm', 'Z', 'a'], array_map(static fn($r): string => $r->roleKey, $roles->paginateForManagement(new ImageRoleListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED), new PageRequest(1, 10, 'id', 'DESC'))->data));

        self::assertSame($ids['Z'], $roles->getByKeyForManagement('z')->id, 'The key compares under the collation.');
        $this->assertThrows(ImageRoleNotFoundException::class, fn() => $roles->getByKeyForManagement('gone'));
        self::assertSame($ids['gone'], $roles->getByKeyForManagement('GONE', ProductDeletedStateEnum::DELETED_ONLY)->id);
        $this->assertThrows(ImageRoleNotFoundException::class, fn() => $roles->getByKeyForManagement('missing'));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $roles->getByKeyForManagement(''));
    }

    public function testImageAssignmentCriteriaAreExact(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        $hero = $this->imageRoleManagementService()->create(new CreateImageRoleCommand('hero'))->roleId;
        $service = $this->imageAssignmentManagementService();
        $p1 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $p2 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2, $hero, 'en', 'web'))->assignmentId;
        $p3 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 3, null, 'en'))->assignmentId;
        $p4 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 4))->assignmentId;
        $v1 = $service->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, 1))->assignmentId;
        $deleted = $service->create(new CreateImageAssignmentCommand($p->productId, null, 9))->assignmentId;
        $service->softDelete($deleted);
        $service->create(new CreateImageAssignmentCommand($q->productId, null, 1));

        $ids = fn(?ImageAssignmentTargetFilter $target = null, ?ImageAssignmentRoleFilter $role = null, ?ImageAssignmentLanguageFilter $language = null, ?ImageAssignmentPlatformFilter $platform = null, ProductDeletedStateEnum $deleted = ProductDeletedStateEnum::NON_DELETED): array => self::sorted(array_map(
            static fn($a): int => $a->id,
            $service->listForManagement(new ImageAssignmentListCriteriaDTO($p->productId, $target, $role, $language, $platform, $deleted))->items(),
        ));

        self::assertSame(self::sorted([$p1, $p2, $p3, $p4, $v1]), $ids(), 'Omitted selectors match every non-deleted assignment of the Product.');
        self::assertSame(self::sorted([$p1, $p2, $p3, $p4]), $ids(ImageAssignmentTargetFilter::productLevel()), 'The Product level never returns Variant rows.');
        self::assertSame([$v1], $ids(ImageAssignmentTargetFilter::variant($p->variantId)), 'A Variant target never returns Product-level rows.');
        self::assertSame([], $ids(ImageAssignmentTargetFilter::variant($q->variantId)), 'A Variant of another Product matches nothing.');
        self::assertSame(self::sorted([$p1, $p3, $p4, $v1]), $ids(null, ImageAssignmentRoleFilter::exactNull()), 'An exact NULL role never matches a role.');
        self::assertSame([$p2], $ids(null, ImageAssignmentRoleFilter::forRole($hero)));
        self::assertSame(self::sorted([$p1, $p4, $v1]), $ids(null, null, ImageAssignmentLanguageFilter::exactNull()), 'An exact NULL language never matches a language.');
        self::assertSame(self::sorted([$p2, $p3]), $ids(null, null, ImageAssignmentLanguageFilter::exact('EN')), 'A language compares under the collation and never matches NULL.');
        self::assertSame(self::sorted([$p1, $p3, $p4, $v1]), $ids(null, null, null, ImageAssignmentPlatformFilter::exactNull()));
        self::assertSame([$p2], $ids(null, null, null, ImageAssignmentPlatformFilter::exact('web')));
        self::assertSame([$p1, $p4], $ids(ImageAssignmentTargetFilter::productLevel(), ImageAssignmentRoleFilter::exactNull(), ImageAssignmentLanguageFilter::exactNull(), ImageAssignmentPlatformFilter::exactNull()));
        self::assertSame([$deleted], $ids(null, null, null, null, ProductDeletedStateEnum::DELETED_ONLY));
        self::assertTrue($service->listForManagement(new ImageAssignmentListCriteriaDTO(999999))->isEmpty());

        $listed = array_map(static fn($a): int => $a->id, $service->listForManagement(new ImageAssignmentListCriteriaDTO($p->productId))->items());
        $paged = [];
        for ($page = 1; $page <= 3; ++$page) {
            $result = $service->paginateForManagement(new ImageAssignmentListCriteriaDTO($p->productId, maxResults: 1), new PageRequest($page, 2));
            self::assertSame([5, 5, 3, 'business_order'], [$result->total, $result->filtered, $result->totalPages, $result->sortBy]);
            $paged = [...$paged, ...array_map(static fn($a): int => $a->id, $result->data)];
        }
        self::assertSame($listed, $paged, 'The business order of the pages equals the list order (scope, position, identity).');
        self::assertSame([$p1, $p4], array_slice(array_values(array_filter($listed, static fn(int $id): bool => in_array($id, [$p1, $p4], true))), 0, 2), 'Within one exact scope, by position.');
        $byId = $service->paginateForManagement(new ImageAssignmentListCriteriaDTO($p->productId, ImageAssignmentTargetFilter::productLevel()), new PageRequest(1, 100, 'id', 'DESC'));
        self::assertSame([$p4, $p3, $p2, $p1], array_map(static fn($a): int => $a->id, $byId->data));
        self::assertSame(1, $service->paginateForManagement(new ImageAssignmentListCriteriaDTO($p->productId, null, null, ImageAssignmentLanguageFilter::exactNull(), ImageAssignmentPlatformFilter::exactNull(), ProductDeletedStateEnum::DELETED_ONLY), new PageRequest())->filtered);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
