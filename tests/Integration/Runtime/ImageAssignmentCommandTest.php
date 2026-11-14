<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentAlreadyExistsException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleKeyAlreadyExistsException;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;

/**
 * Image Roles and Image Assignments against real MySQL: identity and target integrity, the exact
 * scope key shared with the Ordering API, and the default operations under a soft-deleted target:
 * set-default needs a live assignment, target, and Role; clear-default works even
 * under a deleted target or Role; nothing is ever promoted; inactivity alone blocks nothing.
 */
final class ImageAssignmentCommandTest extends RuntimeTestCase
{
    public function testRoleLifecycle(): void
    {
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        self::assertSame(ImageRoleStatusEnum::ACTIVE, $roles->getByIdForManagement($hero)->status);
        self::assertSame('hero', $roles->getByIdForManagement($hero)->roleKey);
        $this->assertThrows(ImageRoleKeyAlreadyExistsException::class, fn() => $roles->create(new CreateImageRoleCommand('HERO')));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateImageRoleCommand(' '));

        $roles->updateStatus($hero, ImageRoleStatusEnum::INACTIVE);
        self::assertSame(ImageRoleStatusEnum::INACTIVE, $roles->getByIdForManagement($hero)->status);
        $this->assertNoWrite(
            'maa_product_image_roles',
            fn() => $this->imageRoleManagementService(self::laterClock())->updateStatus($hero, ImageRoleStatusEnum::INACTIVE),
            'A same-status request writes nothing.',
        );

        $roles->softDelete($hero);
        $this->assertThrows(ImageRoleNotFoundException::class, fn() => $roles->getByIdForManagement($hero));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $roles->updateStatus($hero, ImageRoleStatusEnum::ACTIVE));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $roles->softDelete($hero));
        $this->assertThrows(ImageRoleKeyAlreadyExistsException::class, fn() => $roles->create(new CreateImageRoleCommand('hero')), 'The key stays reserved.');
        $roles->restore($hero);
        self::assertSame(ImageRoleStatusEnum::INACTIVE, $roles->getByIdForManagement($hero)->status, 'Restore preserves the saved status.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $roles->restore($hero));
        $this->assertThrows(ImageRoleNotFoundException::class, fn() => $roles->softDelete(999999));
    }

    /**
     * "Changing a Role's status or deleting it never changes an assignment": every column of every
     * assignment row, `is_default` and `updated_at` included, is identical before and after each Role
     * lifecycle call, made through a Clock later than the setup so any write would show.
     */
    public function testARoleStatusChangeDeletionOrRestoreNeverChangesAnAssignment(): void
    {
        $p = $this->namedProduct('P');
        $hero = $this->imageRoleManagementService()->create(new CreateImageRoleCommand('hero'))->roleId;
        $assignments = $this->imageAssignmentManagementService();
        $default = $assignments->create(new CreateImageAssignmentCommand($p->productId, null, 1, $hero))->assignmentId;
        $assignments->setDefault($default);
        $assignments->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, 2, $hero, 'en', 'web'));
        $deleted = $assignments->create(new CreateImageAssignmentCommand($p->productId, null, 3, $hero))->assignmentId;
        $assignments->softDelete($deleted);
        $roles = $this->imageRoleManagementService(self::laterClock());

        $this->assertNoWrite('maa_product_image_assignments', fn() => $roles->updateStatus($hero, ImageRoleStatusEnum::INACTIVE), 'Deactivating a Role changes no assignment.');
        $this->assertNoWrite('maa_product_image_assignments', fn() => $roles->updateStatus($hero, ImageRoleStatusEnum::ACTIVE), 'Activating a Role changes no assignment.');
        $this->assertNoWrite('maa_product_image_assignments', fn() => $roles->softDelete($hero), 'Deleting a Role changes no assignment.');
        $this->assertNoWrite('maa_product_image_assignments', fn() => $roles->restore($hero), 'Restoring a Role changes no assignment.');
        self::assertSame(3, self::rowCount('maa_product_image_assignments', 'role_id = ' . $hero));
    }

    public function testCreateValidatesTargetRoleAndIdentityAndOrdersWithinTheExactScope(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        $retired = $roles->create(new CreateImageRoleCommand('retired'))->roleId;
        $roles->updateStatus($retired, ImageRoleStatusEnum::INACTIVE);
        $gone = $roles->create(new CreateImageRoleCommand('gone'))->roleId;
        $roles->softDelete($gone);
        $service = $this->imageAssignmentManagementService();

        $a1 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 10))->assignmentId;
        $a2 = $service->create(new CreateImageAssignmentCommand($p->productId, null, '11'))->assignmentId;
        $v1 = $service->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, 10))->assignmentId;
        $en = $service->create(new CreateImageAssignmentCommand($p->productId, null, 10, $hero, 'en', 'web'))->assignmentId;
        self::assertSame(1, $service->getByIdForManagement($a1)->displayOrder);
        self::assertSame(2, $service->getByIdForManagement($a2)->displayOrder, 'Same exact scope: next position.');
        self::assertSame(1, $service->getByIdForManagement($v1)->displayOrder, 'The Variant target is another scope.');
        self::assertSame(1, $service->getByIdForManagement($en)->displayOrder);
        $dto = $service->getByIdForManagement($en);
        self::assertSame([$p->productId, null, 10, $hero, 'en', 'web', false], [$dto->productId, $dto->variantId, $dto->mediaAssetId, $dto->roleId, $dto->languageCode, $dto->platform, $dto->isDefault]);

        $this->assertThrows(ImageAssignmentAlreadyExistsException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 10)));
        $this->assertThrows(ImageAssignmentAlreadyExistsException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 10, $hero, 'EN', 'WEB')), 'Scope strings compare case-insensitively.');
        $service->softDelete($a2);
        $this->assertThrows(ImageAssignmentAlreadyExistsException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 11)), 'A soft-deleted identity stays reserved; restore it instead.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, $q->variantId, 10)), 'A Variant of another Product.');
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, 999999, 10)));
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->create(new CreateImageAssignmentCommand(999999, null, 10)));
        $this->assertThrows(ImageRoleNotFoundException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 12, 999999)));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 12, $retired)), 'An inactive Role cannot take new assignments.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->create(new CreateImageAssignmentCommand($p->productId, null, 12, $gone)));
        $this->productManagementService()->softDelete($q->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->create(new CreateImageAssignmentCommand($q->productId, null, 10)), 'A deleted Product takes no new assignment.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateImageAssignmentCommand($p->productId, null, '042'));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateImageAssignmentCommand($p->productId, null, 0));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateImageAssignmentCommand($p->productId, null, 1, null, ''));
        self::assertSame(4, self::rowCount('maa_product_image_assignments'));
    }

    public function testCollationEquivalentScopesShareOrderingAndDefault(): void
    {
        $product = $this->namedProduct('EQUIVALENT-SCOPES')->productId;
        $this->activateProduct($product);
        $assignments = $this->imageAssignmentManagementService();
        $consumer = $this->imageAssignmentConsumerService();

        $first = $assignments->create(new CreateImageAssignmentCommand($product, null, 10, null, 'en', 'café'))->assignmentId;
        $second = $assignments->create(new CreateImageAssignmentCommand($product, null, 11, null, 'en ', 'CAFE '))->assignmentId;
        $third = $assignments->create(new CreateImageAssignmentCommand($product, null, 12, null, 'EN', "cafe\u{0301}"))->assignmentId;
        self::assertSame(1, $assignments->getByIdForManagement($first)->displayOrder);
        self::assertSame(2, $assignments->getByIdForManagement($second)->displayOrder);
        self::assertSame(3, $assignments->getByIdForManagement($third)->displayOrder);

        $assignments->setDefault($first);
        $assignments->setDefault($second);
        $items = $consumer->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($product, null, null, 'en', 'café'))->items();
        self::assertSame([$first, $second, $third], array_map(static fn($item): int => $item->id, $items));
        self::assertSame([false, true, false], array_map(static fn($item): bool => $item->isDefault, $items));
    }

    public function testDefaultReplacementIsAtomicWithinTheExactScopeAndNeverPromotes(): void
    {
        $p = $this->namedProduct('P');
        $service = $this->imageAssignmentManagementService();
        $a1 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $a2 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $en = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1, null, 'en'))->assignmentId;

        $service->setDefault($a1);
        self::assertSame([$a1], $this->defaults());
        $service->setDefault($a2);
        self::assertSame([$a2], $this->defaults(), 'The previous default of the scope is cleared in the same operation.');
        $service->setDefault($en);
        self::assertSame([$a2, $en], $this->defaults(), 'Another exact scope keeps its own default.');
        $later = $this->imageAssignmentManagementService(self::laterClock());
        $this->assertNoWrite('maa_product_image_assignments', fn() => $later->setDefault($a2), 'Setting the current default writes nothing.');

        $later->clearDefault($a2);
        self::assertSame([$en], $this->defaults(), 'Clearing promotes no other assignment.');
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_image_assignments WHERE id = :i', ['i' => $a2]), 'A real write carries the later instant.');
        $this->assertNoWrite('maa_product_image_assignments', fn() => $later->clearDefault($a1), 'Clearing a non-default writes nothing.');
        self::assertSame([$en], $this->defaults());

        $service->setDefault($a1);
        $service->softDelete($a1);
        self::assertSame([$en], $this->defaults(), 'Deleting the default clears the marker and promotes nothing.');
        self::assertFalse($service->getByIdForManagement($a1, ProductDeletedStateEnum::DELETED_ONLY)->isDefault);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->setDefault($a1), 'A soft-deleted assignment cannot become default.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->clearDefault($a1));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($a1));
        $service->restore($a1);
        self::assertFalse($service->getByIdForManagement($a1)->isDefault, 'Restore leaves the row non-default.');
        self::assertSame([$en], $this->defaults());
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($a1));
        $this->assertThrows(ImageAssignmentNotFoundException::class, fn() => $service->setDefault(999999));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $service->clearDefault(0));
    }

    public function testDefaultOperationsUnderInactiveOrDeletedTargetsAndRoles(): void
    {
        $p = $this->namedProduct('P');
        $color = $this->preparedOption($p->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $m = $this->preparedValue($this->preparedOption($p->productId, 'size', 'Size'), 'm', 'M');
        $size = $this->optionValueManagementService()->getByIdForManagement($m)->optionId;
        $l = $this->preparedValue($size, 'l', 'L');
        [$redM, $redL, $blueM, $blueL] = $this->productManagementService()->reconfigure($this->reconfiguration(
            $p->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $red], [$size, $l]]], [[[$color, $blue], [$size, $m]]], [[[$color, $blue], [$size, $l]]]],
        ))->createdVariantIds;
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        $service = $this->imageAssignmentManagementService();
        $onProduct = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1, $hero))->assignmentId;
        $onVariant = $service->create(new CreateImageAssignmentCommand($p->productId, $redL, 1, $hero))->assignmentId;
        $plain = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;

        // Inactivity alone blocks nothing.
        $this->productManagementService()->updateStatus($p->productId, ProductStatusEnum::INACTIVE);
        $roles->updateStatus($hero, ImageRoleStatusEnum::INACTIVE);
        $service->setDefault($onProduct);
        $service->setDefault($onVariant);
        self::assertSame([$onProduct, $onVariant], $this->defaults());

        // A soft-deleted Role blocks set-default but not clear-default.
        $roles->softDelete($hero);
        $service->clearDefault($onProduct);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->setDefault($onProduct));
        self::assertSame([$onVariant], $this->defaults());
        $roles->restore($hero);
        $service->setDefault($onProduct);

        // A soft-deleted target Variant blocks set-default, soft delete, and restore, but not clear-default.
        $this->variantManagementService()->softDelete($redL);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($onVariant));
        $service->clearDefault($onVariant);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->setDefault($onVariant));
        self::assertSame([$onProduct], $this->defaults());
        $this->variantManagementService()->restore($redL);
        $service->setDefault($onVariant);

        // A soft-deleted Product blocks everything but clear-default and reads.
        $service->softDelete($plain);
        $this->productManagementService()->softDelete($p->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->setDefault($plain));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($plain));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($onProduct));
        $service->clearDefault($onProduct);
        self::assertSame([$onVariant], $this->defaults(), 'Clearing under a deleted Product promotes nothing.');
        self::assertSame($plain, $service->getByIdForManagement($plain, ProductDeletedStateEnum::DELETED_ONLY)->id);
        $this->productManagementService()->restore($p->productId);
        $service->restore($plain);
        foreach ([$redM, $blueM, $blueL] as $untouched) {
            self::assertNull($this->variantManagementService()->getByIdForManagement($untouched)->deletedAt, 'The other Variants were never touched.');
        }
    }

    /** @return list<int> */
    private function defaults(): array
    {
        $statement = self::$pdo->query('SELECT id FROM maa_product_image_assignments WHERE is_default = 1 ORDER BY id');
        self::assertNotFalse($statement);
        /** @var list<array{0: int|string}> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_NUM);

        return array_map(static fn(array $row): int => (int) $row[0], $rows);
    }
}
