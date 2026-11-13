<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Product\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Product\Consumer\Service\ProductConsumerService;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Service\ProductManagementService;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Product\Repository\ProductQueryReaderInterface;
use Maatify\Product\Tests\Support\CallLog;
use Maatify\Product\Tests\Support\UnusedProductConfigurationRepository;
use Maatify\Product\Tests\Support\UnusedProductLifecycleWrites;
use Maatify\Product\Tests\Support\UnusedProductListReads;
use Maatify\Product\Tests\Support\UnusedVariantLifecycleWrites;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProductServiceTest extends TestCase
{
    private CallLog $calls;

    protected function setUp(): void
    {
        $this->calls = new CallLog();
    }

    public function testCreateRunsBothWritesInOneTransactionAndReturnsBothIdentities(): void
    {
        $service = $this->service();

        $created = $service->create(new CreateProductCommand('P-1'));

        self::assertSame(11, $created->productId);
        self::assertSame(22, $created->variantId);
        self::assertSame(
            ['transaction:begin', 'product:create:P-1', 'variant:create-empty:11', 'transaction:end'],
            $this->calls->entries(),
        );
    }

    public function testCreateUsesOneHostClockInstantForBothWrites(): void
    {
        $log = new CallLog();
        $products = new class ($log) implements ProductCommandRepositoryInterface {
            use UnusedProductLifecycleWrites;

            public function __construct(private CallLog $log) {}

            public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int
            {
                $this->log->record('instant:' . spl_object_id($occurredAt) . ':' . $occurredAt->format('Y-m-d H:i:s'));

                return 1;
            }
        };
        $variants = new class ($log) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private CallLog $log) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                $this->log->record('instant:' . spl_object_id($occurredAt) . ':' . $occurredAt->format('Y-m-d H:i:s'));

                return 2;
            }
        };

        $this->service(products: $products, variants: $variants)->create(new CreateProductCommand('P-1'));

        $entries = $log->entries();
        self::assertCount(2, $entries);
        self::assertSame($entries[0], $entries[1]);
        self::assertStringEndsWith(':2026-01-01 00:00:00', $entries[0]);
    }

    public function testCreateDoesNotWriteTheVariantWhenTheProductWriteFails(): void
    {
        $products = new class implements ProductCommandRepositoryInterface {
            use UnusedProductLifecycleWrites;

            public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int
            {
                throw ProductCodeAlreadyExistsException::withCode($command->code);
            }
        };

        try {
            $this->service(products: $products)->create(new CreateProductCommand('P-1'));
            self::fail('The conflict must propagate.');
        } catch (ProductCodeAlreadyExistsException) {
            self::assertNotContains('variant:create-empty:11', $this->calls->entries());
        }
    }

    public function testCreatePropagatesAVariantFailureUnchangedForTheTransactionToRollBack(): void
    {
        $failure = new RuntimeException('variant write failed');
        $variants = new class ($failure) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private RuntimeException $failure) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                throw $this->failure;
            }
        };

        try {
            $this->service(variants: $variants)->create(new CreateProductCommand('P-1'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testGetByIdReturnsAnActiveNonDeletedProduct(): void
    {
        $dto = $this->dto(ProductStatusEnum::ACTIVE, null);

        self::assertSame($dto, $this->consumer($this->readerOf($dto))->getById(5));
    }

    public function testGetByIdHidesInactiveAndSoftDeletedProducts(): void
    {
        foreach ([$this->dto(ProductStatusEnum::INACTIVE, null), $this->dto(ProductStatusEnum::ACTIVE, new \DateTimeImmutable('2026-01-02'))] as $dto) {
            try {
                $this->consumer($this->readerOf($dto))->getById(5);
                self::fail('A non-visible Product must not be returned.');
            } catch (ProductNotFoundException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testManagementReadFiltersOnlyByDeletedState(): void
    {
        $live = $this->dto(ProductStatusEnum::INACTIVE, null);
        $deleted = $this->dto(ProductStatusEnum::INACTIVE, new \DateTimeImmutable('2026-01-02'));

        self::assertSame($live, $this->service(reader: $this->readerOf($live))->getByIdForManagement(5));
        self::assertSame($live, $this->service(reader: $this->readerOf($live))->getByIdForManagement(5, ProductDeletedStateEnum::INCLUDE_DELETED));
        self::assertSame($deleted, $this->service(reader: $this->readerOf($deleted))->getByIdForManagement(5, ProductDeletedStateEnum::DELETED_ONLY));
        self::assertSame($deleted, $this->service(reader: $this->readerOf($deleted))->getByIdForManagement(5, ProductDeletedStateEnum::INCLUDE_DELETED));

        foreach ([[$deleted, ProductDeletedStateEnum::NON_DELETED], [$live, ProductDeletedStateEnum::DELETED_ONLY]] as [$dto, $state]) {
            try {
                $this->service(reader: $this->readerOf($dto))->getByIdForManagement(5, $state);
                self::fail('The deleted-state filter must exclude the Product.');
            } catch (ProductNotFoundException) {
            }
        }
    }

    private function dto(ProductStatusEnum $status, ?\DateTimeImmutable $deletedAt): ProductDTO
    {
        $at = new \DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new ProductDTO(5, 'P-5', $status, $at, $at, $deletedAt);
    }

    private function readerOf(ProductDTO $dto): ProductQueryReaderInterface
    {
        return new class ($dto) implements ProductQueryReaderInterface {
            use UnusedProductListReads;

            public function __construct(private ProductDTO $dto) {}

            public function findById(int $productId): ?ProductDTO
            {
                return $productId === $this->dto->id ? $this->dto : null;
            }
        };
    }

    public function testNonPositiveIdentitiesAreRejected(): void
    {
        foreach ([
            fn() => $this->consumer()->getById(0),
            fn() => $this->service()->getByIdForManagement(-1),
        ] as $call) {
            try {
                $call();
                self::fail('A non-positive identity must be rejected.');
            } catch (\Maatify\Product\Common\Exception\ProductInvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetByIdThrowsNotFoundForAnUnknownIdentity(): void
    {
        $this->expectException(ProductNotFoundException::class);
        $this->consumer()->getById(404);
    }

    private function service(
        ?ProductCommandRepositoryInterface $products = null,
        ?VariantCommandRepositoryInterface $variants = null,
        ?ProductQueryReaderInterface $reader = null,
    ): ProductManagementService {
        $calls = $this->calls;

        $products ??= new class ($calls) implements ProductCommandRepositoryInterface {
            use UnusedProductLifecycleWrites;

            public function __construct(private CallLog $calls) {}

            public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int
            {
                $this->calls->record('product:create:' . $command->code);

                return 11;
            }
        };
        $variants ??= new class ($calls) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private CallLog $calls) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                $this->calls->record('variant:create-empty:' . $productId);

                return 22;
            }
        };
        $reader ??= self::emptyReader();
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

        return new ProductManagementService($products, $variants, $reader, new UnusedProductConfigurationRepository(), $transactions, $clock);
    }

    private function consumer(?ProductQueryReaderInterface $reader = null): ProductConsumerService
    {
        return new ProductConsumerService($reader ?? self::emptyReader());
    }

    private static function emptyReader(): ProductQueryReaderInterface
    {
        return new class implements ProductQueryReaderInterface {
            use UnusedProductListReads;

            public function findById(int $productId): ?ProductDTO
            {
                return null;
            }
        };
    }
}
