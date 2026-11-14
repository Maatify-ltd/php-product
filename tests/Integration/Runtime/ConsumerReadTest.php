<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Option\Consumer\Criteria\OptionVisibleListCriteriaDTO;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\OptionValue\Consumer\Criteria\OptionValueVisibleListCriteriaDTO;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;

/**
 * The consumer reads of Options (`OPT-R2`), Option Values (`OVL-R2`), and exact-scope Image
 * Assignments (`IAS-R2`) against real MySQL: Options and Values are visible only while their
 * Product has a consumer-visible Variant under the shared Variant visibility rule (including the
 * product-wide unrepresented-Value rule), and assignments only in their exact scope under an active owner chain.
 */
final class ConsumerReadTest extends RuntimeTestCase
{
    public function testOptionsAndValuesFollowTheVariantVisibilityRule(): void
    {
        $p = $this->namedProduct('P');
        $color = $this->preparedOption($p->productId, 'color', 'Color');
        $size = $this->preparedOption($p->productId, 'size', 'Size');
        $material = $this->preparedOption($p->productId, 'material', 'Material');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $green = $this->preparedValue($color, 'green', 'Green');
        $medium = $this->preparedValue($size, 'm', 'Medium');
        $this->preparedValue($material, 'cotton', 'Cotton');
        $this->productManagementService()->reconfigure($this->reconfiguration($p->productId, [$color => [$red, $blue], $size => [$medium]], [
            [[[$color, $red], [$size, $medium]]],
            [[[$color, $blue], [$size, $medium]]],
        ]));
        $options = $this->optionConsumerService();
        $values = $this->optionValueConsumerService();
        $visibleOptions = fn(int $productId = 0, int $max = 100): array => array_map(static fn($o): int => $o->id, $options->listVisibleByProductId($productId > 0 ? $productId : $p->productId, new OptionVisibleListCriteriaDTO($max))->items());
        $visibleValues = fn(int $optionId, int $max = 100): array => array_map(static fn($v): int => $v->id, $values->listVisibleByOptionId($optionId, new OptionValueVisibleListCriteriaDTO($max))->items());

        self::assertSame([], $visibleOptions(), 'An inactive Product shows no Option.');
        self::assertSame([], $visibleValues($color));
        $this->activateProduct($p->productId);

        self::assertSame([$color, $size], $visibleOptions(), 'Active, labelled Options by display order; the inactive one is excluded.');
        self::assertSame([$red, $blue], $visibleValues($color), 'Represented Values by display order; the inactive one is excluded.');
        self::assertSame([$medium], $visibleValues($size));
        self::assertSame([], $visibleValues($material), 'A Value under an inactive Option is excluded.');
        $this->optionManagementService()->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($size, 1));
        $this->optionValueManagementService()->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($blue, 1));
        self::assertSame([$size, $color], $visibleOptions());
        self::assertSame([$blue, $red], $visibleValues($color));
        self::assertSame([$size], $visibleOptions(0, 1), 'maxResults bounds the list.');
        self::assertSame([$blue], $visibleValues($color, 1));

        // The product-wide rule: an active Value represented by no active valid Variant hides the whole Product.
        $this->execute("UPDATE maa_product_option_values SET status = 'active' WHERE id = :i", ['i' => $green]);
        self::assertSame([], $visibleOptions(), 'A broken configuration hides every Option.');
        self::assertSame([], $visibleValues($color), 'A broken configuration hides every Value.');
        $this->execute("UPDATE maa_product_option_values SET status = 'inactive' WHERE id = :i", ['i' => $green]);
        self::assertSame([$size, $color], $visibleOptions(), 'The reads recover with the configuration.');

        // An active Option whose only label is removed makes every Variant invalid.
        $this->execute('UPDATE maa_product_option_contents SET deleted_at = :t WHERE option_id = :i', ['t' => self::SEED_TS, 'i' => $size]);
        self::assertSame([], $visibleOptions(), 'An unlabelled active Option hides the Product.');
        self::assertSame([], $visibleValues($color));
        $this->execute('UPDATE maa_product_option_contents SET deleted_at = NULL WHERE option_id = :i', ['i' => $size]);
        self::assertSame([$blue, $red], $visibleValues($color));

        $q = $this->namedProduct('Q');
        $this->activateProduct($q->productId);
        self::assertSame([], $visibleOptions($q->productId), 'A simple Product has no selectable Option.');
        self::assertSame([], $visibleOptions(999999));
        self::assertSame([], $visibleValues(999999));
        self::assertSame([], $visibleValues($material), 'Still nothing under the inactive Option.');

        $this->productManagementService()->updateStatus($p->productId, ProductStatusEnum::INACTIVE);
        self::assertSame([], $visibleOptions());
        $this->productManagementService()->softDelete($p->productId);
        self::assertSame([], $visibleValues($color), 'A deleted Product shows nothing.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $options->listVisibleByProductId(0));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $values->listVisibleByOptionId(-1));
    }

    public function testImageAssignmentsAreReadInTheirExactScopeOnly(): void
    {
        $p = $this->namedProduct('P');
        $color = $this->preparedOption($p->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $this->productManagementService()->reconfigure($this->reconfiguration($p->productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));
        $this->activateProduct($p->productId);
        $variants = array_map(static fn($v): int => $v->id, $this->variantManagementService()->listByProductIdForManagement($p->productId)->items());
        [$original, $vr, $vb] = $variants;
        $q = $this->namedProduct('Q');
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        $old = $roles->create(new CreateImageRoleCommand('old'))->roleId;
        $gone = $roles->create(new CreateImageRoleCommand('gone'))->roleId;
        $service = $this->imageAssignmentManagementService();
        $consumer = $this->imageAssignmentConsumerService();
        $create = fn(?int $variant, int $media, ?int $role = null, ?string $language = null, ?string $platform = null): int => $service->create(new CreateImageAssignmentCommand($p->productId, $variant, $media, $role, $language, $platform))->assignmentId;
        $a1 = $create(null, 1);
        $a2 = $create(null, 2);
        $a3 = $create(null, 3, $hero, 'en', 'web');
        $a4 = $create(null, 4, null, 'en');
        $a5 = $create($vr, 5);
        $create(null, 6, $old);
        $create(null, 7, $gone);
        $deleted = $create(null, 8);
        $create($original, 9);
        $service->softDelete($deleted);
        $roles->updateStatus($old, ImageRoleStatusEnum::INACTIVE);
        $roles->softDelete($gone);
        $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a2, 1));

        $read = fn(?int $variant, ?int $role = null, ?string $language = null, ?string $platform = null, int $max = 100, int $productId = 0): array => array_map(
            static fn($a): int => $a->id,
            $consumer->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId > 0 ? $productId : $p->productId, $variant, $role, $language, $platform, $max))->items(),
        );

        self::assertSame([$a2, $a1], $read(null), 'The all-NULL Product-level scope, by position; NULL never matches a value and a deleted row is hidden.');
        self::assertSame([$a2], $read(null, max: 1));
        self::assertSame([$a3], $read(null, $hero, 'EN', 'WEB'), 'Strings compare under the collation.');
        self::assertSame([], $read(null, $hero, 'en', null), 'A NULL platform does not match "web".');
        self::assertSame([$a4], $read(null, null, 'en'));
        self::assertSame([$a5], $read($vr), 'The Variant scope.');
        self::assertSame([], $read($vb), 'No Product-level fallback for a Variant.');
        self::assertFalse(in_array($a5, $read(null), true), 'No Variant-level substitution for the Product level.');
        self::assertSame([], $read(null, $old), 'An inactive Role hides its assignments.');
        self::assertSame([], $read(null, $gone), 'A deleted Role hides its assignments.');
        self::assertSame([], $read($original), 'An inactive target Variant hides its assignments.');
        $this->variantManagementService()->softDelete($original);
        self::assertSame([], $read($original), 'A deleted target Variant hides its assignments.');
        self::assertSame([], $read($vr, productId: $q->productId), 'A Variant of another Product matches nothing.');
        self::assertFalse($consumer->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($p->productId, null, null, null, null))->items()[0]->isDefault, 'The read selects no default.');

        $this->productManagementService()->updateStatus($p->productId, ProductStatusEnum::INACTIVE);
        self::assertSame([], $read(null), 'An inactive Product hides its assignments.');
        self::assertSame([], $read($vr));
    }
}
