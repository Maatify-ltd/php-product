<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Repository\Pdo\PdoImageAssignmentCommandRepository;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use PDOException;

/**
 * `restoreAsDefault()` (`IAS-07`) against real MySQL: it restores a soft-deleted
 * assignment as the default of its exact scope at the end of the scope's order, never replaces a
 * live default (typed conflict, nothing written), keeps the `setDefault()` preconditions, leaves a
 * Host transaction active and untouched on failure, and maps only a duplicate on the default-scope
 * unique key to the conflict.
 */
final class RestoreAsDefaultTest extends RuntimeTestCase
{
    private const string TABLE = 'maa_product_image_assignments';
    private const string FAILING_TRIGGER = 'trg_product_it_restore_as_default_duplicate';

    protected function cleanUp(): void
    {
        self::$pdo->exec('DROP TRIGGER IF EXISTS `' . self::FAILING_TRIGGER . '`');
    }

    public function testASoftDeletedAssignmentOfAScopeWithoutADefaultBecomesTheDefaultAtTheEndOfTheScope(): void
    {
        $p = $this->namedProduct('P');
        $service = $this->imageAssignmentManagementService();
        $a1 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $a2 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $a3 = $service->create(new CreateImageAssignmentCommand($p->productId, null, 3))->assignmentId;
        $other = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1, null, 'en'))->assignmentId;
        $service->setDefault($other);
        $service->softDelete($a1);
        self::assertSame(1, $service->getByIdForManagement($a1, ProductDeletedStateEnum::DELETED_ONLY)->displayOrder);

        $this->imageAssignmentManagementService(self::laterClock())->restoreAsDefault($a1);

        $restored = $service->getByIdForManagement($a1);
        self::assertNull($restored->deletedAt);
        self::assertTrue($restored->isDefault);
        self::assertSame(4, $restored->displayOrder, 'The restored row takes the next position of its live scope.');
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM ' . self::TABLE . ' WHERE id = :i', ['i' => $a1]), 'updated_at comes from the Clock.');
        self::assertSame([$a1, $other], $this->defaults(), 'Another exact scope keeps its own default.');
        foreach ([$a2 => 2, $a3 => 3] as $id => $position) {
            self::assertSame($position, $service->getByIdForManagement($id)->displayOrder, 'The other rows of the scope do not move.');
        }
    }

    public function testALiveDefaultOfTheExactScopeConflictsAndNothingIsWritten(): void
    {
        $p = $this->namedProduct('P');
        $service = $this->imageAssignmentManagementService();
        $live = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $deleted = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $service->setDefault($live);
        $service->softDelete($deleted);
        $later = $this->imageAssignmentManagementService(self::laterClock());

        $this->assertNoWrite(self::TABLE, function () use ($later, $deleted, $live): void {
            $conflict = $this->assertThrows(ImageAssignmentDefaultConflictException::class, fn() => $later->restoreAsDefault($deleted));
            self::assertStringContainsString('[' . $deleted . ']', $conflict->getMessage());
            self::assertStringContainsString('[' . $live . ']', $conflict->getMessage(), 'The conflict names the live default.');
        }, 'A conflicting restore-as-default writes nothing and never replaces the live default.');
        self::assertSame([$live], $this->defaults());
    }

    public function testPreconditionsFollowRestoreAndSetDefault(): void
    {
        $p = $this->namedProduct('P');
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        $service = $this->imageAssignmentManagementService();
        $live = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $onRole = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2, $hero))->assignmentId;
        $onVariant = $service->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, 3))->assignmentId;
        $service->softDelete($onRole);
        $service->softDelete($onVariant);
        $later = $this->imageAssignmentManagementService(self::laterClock());

        $this->assertNoWrite(self::TABLE, function () use ($later, $live, $onRole, $onVariant, $roles, $hero, $p): void {
            $this->assertThrows(ProductBusinessRuleException::class, fn() => $later->restoreAsDefault($live), 'A non-deleted assignment cannot be restored.');

            $roles->softDelete($hero);
            $this->assertThrows(ProductBusinessRuleException::class, fn() => $later->restoreAsDefault($onRole), 'A soft-deleted Role blocks it, as for setDefault.');
            $roles->restore($hero);

            $this->execute('UPDATE maa_product_variants SET deleted_at = :t WHERE id = :i', ['t' => self::SEED_TS, 'i' => $p->variantId]);
            $this->assertThrows(ProductBusinessRuleException::class, fn() => $later->restoreAsDefault($onVariant), 'A soft-deleted target Variant blocks it.');
            $this->execute('UPDATE maa_product_variants SET deleted_at = NULL WHERE id = :i', ['i' => $p->variantId]);

            $this->productManagementService()->softDelete($p->productId);
            $this->assertThrows(ProductBusinessRuleException::class, fn() => $later->restoreAsDefault($onRole), 'A soft-deleted target Product blocks it.');
            $this->productManagementService()->restore($p->productId);

            $this->assertThrows(ImageAssignmentNotFoundException::class, fn() => $later->restoreAsDefault(999999));
            $this->assertThrows(ProductInvalidArgumentException::class, fn() => $later->restoreAsDefault(0));
        }, 'A rejected restore-as-default writes no assignment.');
        self::assertSame([], $this->defaults());
    }

    public function testInactivityOfTheProductVariantOrRoleDoesNotBlockIt(): void
    {
        $p = $this->namedProduct('P');
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
        $service = $this->imageAssignmentManagementService();
        $onRole = $service->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, 1, $hero))->assignmentId;
        $service->softDelete($onRole);
        $roles->updateStatus($hero, ImageRoleStatusEnum::INACTIVE);
        self::assertSame(ProductStatusEnum::INACTIVE, $this->productManagementService()->getByIdForManagement($p->productId)->status);
        $this->execute('UPDATE maa_product_variants SET status = \'inactive\' WHERE id = :i', ['i' => $p->variantId]);
        self::assertSame(VariantStatusEnum::INACTIVE, $this->variantManagementService()->getByIdForManagement($p->variantId)->status);

        $service->restoreAsDefault($onRole);

        self::assertSame([$onRole], $this->defaults(), 'An inactive Product, Variant, or Role does not block it.');
    }

    public function testAFailureInsideAHostTransactionLeavesItActiveAndUntouched(): void
    {
        $p = $this->namedProduct('P');
        $service = $this->imageAssignmentManagementService();
        $live = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $deleted = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $service->softDelete($deleted);

        self::$pdo->beginTransaction();
        $service->setDefault($live);
        $hostWork = $service->create(new CreateImageAssignmentCommand($p->productId, null, 3))->assignmentId;
        $this->assertThrows(ImageAssignmentDefaultConflictException::class, fn() => $service->restoreAsDefault($deleted));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restoreAsDefault($live));
        self::assertTrue(self::$pdo->inTransaction(), 'The package must neither commit nor roll back the Host transaction.');
        self::$pdo->commit();

        self::assertSame([$live], $this->defaults(), 'The Host work before the failures is committed.');
        self::assertSame($hostWork, $service->getByIdForManagement($hostWork)->id);
        $row = $service->getByIdForManagement($deleted, ProductDeletedStateEnum::DELETED_ONLY);
        self::assertFalse($row->isDefault, 'The failed calls left no write behind.');
    }

    public function testOnlyADuplicateOnTheDefaultScopeKeyBecomesTheConflict(): void
    {
        $p = $this->namedProduct('P');
        $service = $this->imageAssignmentManagementService();
        $live = $service->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $deleted = $service->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $service->setDefault($live);
        $service->softDelete($deleted);
        $scope = (string) self::scalar('SELECT ordering_scope FROM ' . self::TABLE . ' WHERE id = :i', ['i' => $deleted]);
        $repository = new PdoImageAssignmentCommandRepository(self::$pdo, new ScopedOrderingManager());
        $at = new DateTimeImmutable(self::LATER_TS, new DateTimeZone('UTC'));

        // The service rejects this case before writing; the repository alone proves the final guard.
        $this->assertNoWrite(self::TABLE, function () use ($repository, $deleted, $live, $scope, $at, $p): void {
            self::$pdo->beginTransaction();
            try {
                self::scalar('SELECT id FROM maa_product_products WHERE id = :id FOR UPDATE', ['id' => $p->productId]);
                $conflict = $this->assertThrows(
                    ImageAssignmentDefaultConflictException::class,
                    fn() => $repository->restoreAsDefault($deleted, $p->productId, $scope, $at),
                );
            } finally {
                self::$pdo->rollBack();
            }
            $previous = $conflict->getPrevious();
            self::assertInstanceOf(PDOException::class, $previous);
            self::assertSame(1062, $previous->errorInfo[1] ?? null);
            self::assertStringContainsString('uq_maa_product_image_assignments_default_scope', self::driverMessage($previous));
            self::assertStringContainsString('[' . $live . ']', $conflict->getMessage());
        }, 'The database unique key on the default scope rejects a second live default.');

        // A duplicate on any other key passes through unchanged.
        $service->clearDefault($live);
        $this->imageRoleManagementService()->create(new CreateImageRoleCommand('dup'));
        self::$pdo->exec(
            'CREATE TRIGGER `' . self::FAILING_TRIGGER . '` BEFORE UPDATE ON `' . self::TABLE . '` FOR EACH ROW '
            . "INSERT INTO `maa_product_image_roles` (`role_key`, `status`, `created_at`, `updated_at`) VALUES ('dup', 'active', NOW(), NOW())",
        );
        $other = $this->assertThrows(PDOException::class, fn() => $service->restoreAsDefault($deleted));
        self::assertNotInstanceOf(ImageAssignmentDefaultConflictException::class, $other);
        self::assertInstanceOf(PDOException::class, $other);
        self::assertSame(1062, $other->errorInfo[1] ?? null);
        self::assertStringContainsString('uq_maa_product_image_roles_role_key', self::driverMessage($other));
        self::assertSame([], $this->defaults());
        self::assertNotNull($service->getByIdForManagement($deleted, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt);
    }

    private static function driverMessage(PDOException $exception): string
    {
        $message = $exception->errorInfo[2] ?? null;
        self::assertIsString($message);

        return $message;
    }

    /** @return list<int> */
    private function defaults(): array
    {
        $statement = self::$pdo->query('SELECT id FROM ' . self::TABLE . ' WHERE is_default = 1 ORDER BY id');
        self::assertNotFalse($statement);
        /** @var list<array{0: int|string}> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_NUM);

        return array_map(static fn(array $row): int => (int) $row[0], $rows);
    }
}
