<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\ImageAssignment\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentIdentityDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentStateDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\Management\Service\ImageAssignmentManagementService;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentCommandRepositoryInterface;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentQueryReaderInterface;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentScope;
use Maatify\Product\ImageRole\DTO\ImageRoleStateDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Repository\ImageRoleCommandRepositoryInterface;
use Maatify\Product\Product\DTO\ProductLifecycleStateDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Tests\Support\CallLog;
use Maatify\Product\Variant\DTO\VariantLifecycleStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Lock order and failure classification of the Image Assignment mutations, with fakes that record
 * every repository call: Product, target Variant, Role (set-default and restore-as-default only), then the assignment row,
 * and nothing written when the request matches the stored state.
 */
final class ImageAssignmentServiceTest extends TestCase
{
    private const int ASSIGNMENT = 7;
    private const int PRODUCT = 1;
    private const int VARIANT = 2;
    private const int ROLE = 3;

    private CallLog $calls;
    private ?ImageAssignmentIdentityDTO $identity;
    private ?ProductLifecycleStateDTO $product;
    private ?VariantLifecycleStateDTO $variant;
    private ?ImageRoleStateDTO $role;
    private ?ImageAssignmentStateDTO $assignment;
    private ?int $liveDefault;

    protected function setUp(): void
    {
        $this->calls = new CallLog();
        $this->identity = new ImageAssignmentIdentityDTO(self::PRODUCT, self::VARIANT, self::ROLE);
        $this->product = new ProductLifecycleStateDTO(self::PRODUCT, true, false);
        $this->variant = new VariantLifecycleStateDTO(self::VARIANT, self::PRODUCT, true, false);
        $this->role = new ImageRoleStateDTO(self::ROLE, true, false);
        $this->assignment = $this->assignmentState(isDefault: false, deleted: false);
        $this->liveDefault = null;
    }

    public function testEveryMutationLocksTheOwnersBeforeTheAssignmentRow(): void
    {
        $lead = ['transaction:begin', 'identity:7', 'product:lock:1', 'variant:lock:2'];

        $this->service()->setDefault(self::ASSIGNMENT);
        self::assertSame([...$lead, 'role:lock:3', 'assignment:lock:7', 'assignment:set-default:7', 'transaction:end'], $this->takeCalls());

        $this->assignment = $this->assignmentState(isDefault: true, deleted: false);
        $this->service()->clearDefault(self::ASSIGNMENT);
        self::assertSame([...$lead, 'assignment:lock:7', 'assignment:clear-default:7', 'transaction:end'], $this->takeCalls());

        $this->service()->softDelete(self::ASSIGNMENT);
        self::assertSame([...$lead, 'assignment:lock:7', 'assignment:soft-delete:7', 'transaction:end'], $this->takeCalls());

        $this->assignment = $this->assignmentState(isDefault: false, deleted: true);
        $this->service()->restore(self::ASSIGNMENT);
        self::assertSame([...$lead, 'assignment:lock:7', 'assignment:restore:7', 'transaction:end'], $this->takeCalls());

        $this->assignment = $this->assignmentState(isDefault: false, deleted: false);
        $this->service()->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand(self::ASSIGNMENT, 2));
        self::assertSame([...$lead, 'assignment:lock:7', 'assignment:move:7:2', 'transaction:end'], $this->takeCalls());
    }

    public function testRestoreAsDefaultLocksTheOwnersAndTheAssignmentBeforeReadingTheScopeDefault(): void
    {
        $this->assignment = $this->assignmentState(isDefault: false, deleted: true);

        $this->service()->restoreAsDefault(self::ASSIGNMENT);
        self::assertSame(
            ['transaction:begin', 'identity:7', 'product:lock:1', 'variant:lock:2', 'role:lock:3', 'assignment:lock:7', 'assignment:lock-default:scope', 'assignment:restore-as-default:7', 'transaction:end'],
            $this->takeCalls(),
        );

        $this->identity = new ImageAssignmentIdentityDTO(self::PRODUCT, null, null);
        $this->service()->restoreAsDefault(self::ASSIGNMENT);
        self::assertSame(
            ['transaction:begin', 'identity:7', 'product:lock:1', 'assignment:lock:7', 'assignment:lock-default:scope', 'assignment:restore-as-default:7', 'transaction:end'],
            $this->takeCalls(),
            'A Product target without a Role locks only the Product before the assignment row.',
        );
    }

    public function testRestoreAsDefaultNeverReplacesALiveDefault(): void
    {
        $this->assignment = $this->assignmentState(isDefault: false, deleted: true);
        $this->liveDefault = 9;

        try {
            $this->service()->restoreAsDefault(self::ASSIGNMENT);
            self::fail('A live default of the scope must conflict.');
        } catch (ImageAssignmentDefaultConflictException $conflict) {
            self::assertStringContainsString('[7]', $conflict->getMessage());
            self::assertStringContainsString('[9]', $conflict->getMessage(), 'The conflict names the live default.');
        }
        self::assertSame([], $this->writes());
    }

    public function testRestoreAsDefaultPreconditions(): void
    {
        $this->assignment = $this->assignmentState(isDefault: false, deleted: false);
        $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->restoreAsDefault(self::ASSIGNMENT), 'restoreAsDefault of a live assignment');

        foreach ([new ImageRoleStateDTO(self::ROLE, true, true), null] as $role) {
            $this->setUp();
            $this->assignment = $this->assignmentState(isDefault: false, deleted: true);
            $this->role = $role;
            $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->restoreAsDefault(self::ASSIGNMENT), 'restoreAsDefault under a deleted Role');
        }

        $this->setUp();
        $this->assignment = $this->assignmentState(isDefault: false, deleted: true);
        $this->product = new ProductLifecycleStateDTO(self::PRODUCT, false, false);
        $this->variant = new VariantLifecycleStateDTO(self::VARIANT, self::PRODUCT, false, false);
        $this->role = new ImageRoleStateDTO(self::ROLE, false, false);
        $this->service()->restoreAsDefault(self::ASSIGNMENT);
        self::assertSame(['assignment:restore-as-default:7'], $this->writes(), 'Inactivity alone blocks nothing.');

        $this->setUp();
        $this->assertFails(ProductInvalidArgumentException::class, fn() => $this->service()->restoreAsDefault(0), 'non-positive identity');
        self::assertSame([], $this->calls->entries(), 'A non-positive identity is rejected before any call.');
        self::assertSame([], $this->writes());
    }

    public function testAProductTargetWithoutARoleLocksOnlyTheProductBeforeTheAssignmentRow(): void
    {
        $this->identity = new ImageAssignmentIdentityDTO(self::PRODUCT, null, null);

        $this->service()->setDefault(self::ASSIGNMENT);

        self::assertSame(
            ['transaction:begin', 'identity:7', 'product:lock:1', 'assignment:lock:7', 'assignment:set-default:7', 'transaction:end'],
            $this->calls->entries(),
        );
    }

    public function testARequestMatchingTheStoredStateWritesNothing(): void
    {
        $this->assignment = $this->assignmentState(isDefault: true, deleted: false);
        $this->service()->setDefault(self::ASSIGNMENT);
        self::assertSame([], $this->writes(), 'Setting the current default writes nothing.');

        $this->assignment = $this->assignmentState(isDefault: false, deleted: false);
        $this->service()->clearDefault(self::ASSIGNMENT);
        self::assertSame([], $this->writes(), 'Clearing a non-default writes nothing.');
    }

    public function testAMissingIdentityIsNotFoundBeforeAnyLock(): void
    {
        $this->identity = null;

        foreach ($this->operations() as $name => $operation) {
            $this->assertFails(ImageAssignmentNotFoundException::class, $operation, $name);
            self::assertSame(['transaction:begin', 'identity:7'], $this->takeCalls(), $name);
        }
    }

    public function testAVanishedProductOrAssignmentRowIsNotFound(): void
    {
        $this->product = null;
        foreach ($this->operations() as $name => $operation) {
            $this->assertFails(ImageAssignmentNotFoundException::class, $operation, $name);
        }

        $this->setUp();
        $this->assignment = null;
        foreach ($this->operations() as $name => $operation) {
            $this->assertFails(ImageAssignmentNotFoundException::class, $operation, $name);
        }
        self::assertSame([], $this->writes());
    }

    public function testADeletedOwnerChainIsInvalidExceptForClearDefault(): void
    {
        $owners = [
            'product' => fn() => $this->product = new ProductLifecycleStateDTO(self::PRODUCT, true, true),
            'variant' => fn() => $this->variant = new VariantLifecycleStateDTO(self::VARIANT, self::PRODUCT, true, true),
            'missing variant' => fn() => $this->variant = null,
        ];
        foreach ($owners as $owner => $breakOwner) {
            foreach (['setDefault' => false, 'softDelete' => false, 'restore' => true, 'restoreAsDefault' => true] as $operation => $deleted) {
                $this->setUp();
                $breakOwner();
                $this->assignment = $this->assignmentState(isDefault: false, deleted: $deleted);
                $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->{$operation}(self::ASSIGNMENT), $operation . ' under a deleted ' . $owner);
                self::assertSame([], $this->writes());
            }

            $this->setUp();
            $breakOwner();
            $this->assignment = $this->assignmentState(isDefault: true, deleted: false);
            $this->service()->clearDefault(self::ASSIGNMENT);
            self::assertSame(['assignment:clear-default:7'], $this->writes(), 'Clear-default works under a deleted ' . $owner . '.');
        }
    }

    public function testADeletedRoleBlocksOnlySetDefault(): void
    {
        foreach ([new ImageRoleStateDTO(self::ROLE, true, true), null] as $role) {
            $this->setUp();
            $this->role = $role;
            $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->setDefault(self::ASSIGNMENT), 'setDefault under a deleted Role');
            self::assertSame([], $this->writes());

            $this->service()->softDelete(self::ASSIGNMENT);
            self::assertSame(['assignment:soft-delete:7'], $this->writes());
        }
    }

    public function testTheLockedAssignmentStateDecidesTheLifecyclePrecondition(): void
    {
        $this->assignment = $this->assignmentState(isDefault: false, deleted: true);
        foreach (['setDefault', 'clearDefault', 'softDelete'] as $operation) {
            $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->{$operation}(self::ASSIGNMENT), $operation . ' of a deleted assignment');
        }

        $this->assignment = $this->assignmentState(isDefault: false, deleted: false);
        $this->assertFails(ProductBusinessRuleException::class, fn() => $this->service()->restore(self::ASSIGNMENT), 'restore of a live assignment');
        self::assertSame([], $this->writes());
    }

    /** @return array<string, callable(): void> */
    private function operations(): array
    {
        return [
            'setDefault' => fn() => $this->service()->setDefault(self::ASSIGNMENT),
            'clearDefault' => fn() => $this->service()->clearDefault(self::ASSIGNMENT),
            'softDelete' => fn() => $this->service()->softDelete(self::ASSIGNMENT),
            'restore' => fn() => $this->service()->restore(self::ASSIGNMENT),
            'restoreAsDefault' => fn() => $this->service()->restoreAsDefault(self::ASSIGNMENT),
        ];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertFails(string $expected, callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (Throwable $caught) {
            self::assertInstanceOf($expected, $caught, $message . ': ' . $caught::class . ': ' . $caught->getMessage());

            return;
        }
        self::fail($message . ': expected ' . $expected . '.');
    }

    /** @return list<string> */
    private function takeCalls(): array
    {
        $entries = $this->calls->entries();
        $this->calls = new CallLog();

        return $entries;
    }

    /** @return list<string> */
    private function writes(): array
    {
        return array_values(array_filter(
            $this->calls->entries(),
            static fn(string $entry): bool => preg_match('/^assignment:(set-default|clear-default|soft-delete|restore|restore-as-default):/', $entry) === 1,
        ));
    }

    private function assignmentState(bool $isDefault, bool $deleted): ImageAssignmentStateDTO
    {
        return new ImageAssignmentStateDTO(self::ASSIGNMENT, self::PRODUCT, self::VARIANT, self::ROLE, 'scope', $isDefault, $deleted);
    }

    private function service(): ImageAssignmentManagementService
    {
        $calls = $this->calls;
        $test = $this;

        $assignments = new class ($calls, $test) implements ImageAssignmentCommandRepositoryInterface {
            public function __construct(private CallLog $calls, private ImageAssignmentServiceTest $test) {}

            public function create(CreateImageAssignmentCommand $command, ImageAssignmentScope $scope, DateTimeImmutable $occurredAt): int
            {
                throw new LogicException('create is not expected in this test.');
            }

            public function findIdentity(int $assignmentId): ?ImageAssignmentIdentityDTO
            {
                $this->calls->record('identity:' . $assignmentId);

                return $this->test->identityFixture();
            }

            public function lockForUpdate(int $assignmentId): ?ImageAssignmentStateDTO
            {
                $this->calls->record('assignment:lock:' . $assignmentId);

                return $this->test->assignmentFixture();
            }

            public function setDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:set-default:' . $assignmentId);

                return true;
            }

            public function clearDefault(int $assignmentId, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:clear-default:' . $assignmentId);

                return true;
            }

            public function softDelete(int $assignmentId, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:soft-delete:' . $assignmentId);

                return true;
            }

            public function restore(int $assignmentId, string $scope, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:restore:' . $assignmentId);

                return true;
            }

            public function lockLiveDefault(int $productId, string $scope): ?int
            {
                $this->calls->record('assignment:lock-default:' . $scope);

                return $this->test->liveDefaultFixture();
            }

            public function restoreAsDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:restore-as-default:' . $assignmentId);

                return true;
            }

            public function updateDisplayOrder(int $assignmentId, string $scope, int $displayOrder, DateTimeImmutable $occurredAt): bool
            {
                $this->calls->record('assignment:move:' . $assignmentId . ':' . $displayOrder);

                return true;
            }
        };

        $reader = new class implements ImageAssignmentQueryReaderInterface {
            public function findById(int $assignmentId): ?ImageAssignmentDTO
            {
                throw new LogicException('findById is not expected in this test.');
            }

            public function listForManagement(ImageAssignmentListCriteriaDTO $criteria): array
            {
                throw new LogicException('listForManagement is not expected in this test.');
            }

            public function paginateForManagement(ImageAssignmentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
            {
                throw new LogicException('paginateForManagement is not expected in this test.');
            }

            public function listVisibleByExactScope(ImageAssignmentExactScopeCriteriaDTO $criteria): array
            {
                throw new LogicException('listVisibleByExactScope is not expected in this test.');
            }
        };

        $products = new class ($calls, $test) implements ProductCommandRepositoryInterface {
            public function __construct(private CallLog $calls, private ImageAssignmentServiceTest $test) {}

            public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int
            {
                throw new LogicException('create is not expected in this test.');
            }

            public function lockForUpdate(int $productId): ?ProductLifecycleStateDTO
            {
                $this->calls->record('product:lock:' . $productId);

                return $this->test->productFixture();
            }

            public function updateStatus(int $productId, ProductStatusEnum $status, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('updateStatus is not expected in this test.');
            }

            public function softDelete(int $productId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('softDelete is not expected in this test.');
            }

            public function restore(int $productId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('restore is not expected in this test.');
            }
        };

        $variants = new class ($calls, $test) implements VariantCommandRepositoryInterface {
            public function __construct(private CallLog $calls, private ImageAssignmentServiceTest $test) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                throw new LogicException('createEmptyComposition is not expected in this test.');
            }

            public function lockForUpdate(int $variantId): ?VariantLifecycleStateDTO
            {
                $this->calls->record('variant:lock:' . $variantId);

                return $this->test->variantFixture();
            }

            public function updateStatus(int $variantId, VariantStatusEnum $status, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('updateStatus is not expected in this test.');
            }

            public function updateIdentifiers(int $variantId, ?string $sku, ?string $barcode, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('updateIdentifiers is not expected in this test.');
            }

            public function softDelete(int $variantId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('softDelete is not expected in this test.');
            }

            public function restore(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('restore is not expected in this test.');
            }

            public function updateDisplayOrder(int $variantId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('updateDisplayOrder is not expected in this test.');
            }
        };

        $roles = new class ($calls, $test) implements ImageRoleCommandRepositoryInterface {
            public function __construct(private CallLog $calls, private ImageAssignmentServiceTest $test) {}

            public function create(CreateImageRoleCommand $command, DateTimeImmutable $occurredAt): int
            {
                throw new LogicException('create is not expected in this test.');
            }

            public function lockForUpdate(int $roleId): ?ImageRoleStateDTO
            {
                $this->calls->record('role:lock:' . $roleId);

                return $this->test->roleFixture();
            }

            public function updateStatus(int $roleId, ImageRoleStatusEnum $status, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('updateStatus is not expected in this test.');
            }

            public function softDelete(int $roleId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('softDelete is not expected in this test.');
            }

            public function restore(int $roleId, DateTimeImmutable $occurredAt): bool
            {
                throw new LogicException('restore is not expected in this test.');
            }
        };

        $transactions = new class ($calls) implements SavepointTransactionRunnerInterface {
            public function __construct(private CallLog $calls) {}

            public function run(callable $callback): mixed
            {
                $this->calls->record('transaction:begin');
                $result = $callback();
                $this->calls->record('transaction:end');

                return $result;
            }
        };

        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
            }

            public function getTimezone(): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }
        };

        return new ImageAssignmentManagementService($assignments, $reader, $products, $variants, $roles, $transactions, $clock);
    }

    /** Fixture accessor for the fakes. */
    public function identityFixture(): ?ImageAssignmentIdentityDTO
    {
        return $this->identity;
    }

    /** Fixture accessor for the fakes. */
    public function productFixture(): ?ProductLifecycleStateDTO
    {
        return $this->product;
    }

    /** Fixture accessor for the fakes. */
    public function variantFixture(): ?VariantLifecycleStateDTO
    {
        return $this->variant;
    }

    /** Fixture accessor for the fakes. */
    public function roleFixture(): ?ImageRoleStateDTO
    {
        return $this->role;
    }

    /** Fixture accessor for the fakes. */
    public function assignmentFixture(): ?ImageAssignmentStateDTO
    {
        return $this->assignment;
    }

    /** Fixture accessor for the fakes. */
    public function liveDefaultFixture(): ?int
    {
        return $this->liveDefault;
    }
}
