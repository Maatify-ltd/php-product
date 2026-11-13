<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Variant\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Tests\Support\UnusedProductConfigurationRepository;
use Maatify\Product\Tests\Support\UnusedVariantLifecycleWrites;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerService;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
use Maatify\Product\Variant\Management\Service\VariantManagementService;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\Product\Variant\Repository\VariantQueryReaderInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;

final class VariantServiceTest extends TestCase
{
    public function testGetByIdUsesTheVisibleReadOnly(): void
    {
        $live = $this->variant(null);
        $reader = $this->reader(visible: $live, all: null);

        self::assertSame($live, (self::consumer($reader))->getById(5));
    }

    public function testGetByIdThrowsNotFoundWhenTheVariantIsNotVisible(): void
    {
        $reader = $this->reader(visible: null, all: $this->variant(null));

        $this->expectException(VariantNotFoundException::class);
        (self::consumer($reader))->getById(5);
    }

    public function testManagementReadFiltersOnlyByDeletedState(): void
    {
        $live = $this->variant(null);
        $deleted = $this->variant(new DateTimeImmutable('2026-01-02'));

        self::assertSame($live, (self::service($this->reader(null, $live)))->getByIdForManagement(5));
        self::assertSame($deleted, (self::service($this->reader(null, $deleted)))->getByIdForManagement(5, ProductDeletedStateEnum::DELETED_ONLY));
        self::assertSame($deleted, (self::service($this->reader(null, $deleted)))->getByIdForManagement(5, ProductDeletedStateEnum::INCLUDE_DELETED));

        foreach ([[$deleted, ProductDeletedStateEnum::NON_DELETED], [$live, ProductDeletedStateEnum::DELETED_ONLY]] as [$variant, $state]) {
            try {
                (self::service($this->reader(null, $variant)))->getByIdForManagement(5, $state);
                self::fail('The deleted-state filter must exclude the Variant.');
            } catch (VariantNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testListsDelegateAndWrapTheReaderResult(): void
    {
        $variant = $this->variant(null);
        $service = self::service($this->reader($variant, $variant, [$variant]));
        $consumer = self::consumer($this->reader($variant, $variant, [$variant]));

        self::assertSame([$variant], $consumer->listByProductId(1)->items());
        self::assertSame([$variant], $service->listByProductIdForManagement(1, new VariantListCriteriaDTO(VariantStatusEnum::ACTIVE))->items());
        self::assertCount(1, $consumer->listByProductId(1, new VariantVisibleListCriteriaDTO(1)));
    }

    public function testNonPositiveIdentitiesAreRejectedBeforeAnyRead(): void
    {
        $service = self::service($this->reader(null, null));
        $consumer = self::consumer($this->reader(null, null));

        foreach ([
            static fn() => $consumer->getById(0),
            static fn() => $service->getByIdForManagement(0),
            static fn() => $consumer->listByProductId(-3),
            static fn() => $service->listByProductIdForManagement(0),
        ] as $call) {
            try {
                $call();
                self::fail('A non-positive identity must be rejected.');
            } catch (ProductInvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function variant(?DateTimeImmutable $deletedAt): VariantDTO
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new VariantDTO(5, 1, null, null, VariantStatusEnum::ACTIVE, 1, [], $at, $at, $deletedAt);
    }

    /** @param list<VariantDTO> $list */
    private function reader(?VariantDTO $visible, ?VariantDTO $all, array $list = []): VariantQueryReaderInterface
    {
        return new class ($visible, $all, $list) implements VariantQueryReaderInterface {
            /** @param list<VariantDTO> $list */
            public function __construct(private ?VariantDTO $visible, private ?VariantDTO $all, private array $list) {}

            public function findById(int $variantId): ?VariantDTO
            {
                return $this->all;
            }

            public function findBySku(string $sku): ?VariantDTO
            {
                return $this->all;
            }

            public function findByBarcode(string $barcode): ?VariantDTO
            {
                return $this->all;
            }

            public function findVisibleById(int $variantId): ?VariantDTO
            {
                return $this->visible;
            }

            public function listForManagement(int $productId, VariantListCriteriaDTO $criteria): array
            {
                return $this->list;
            }

            public function paginateVisible(int $productId, PageRequest $request): PageResultDTO
            {
                throw new \LogicException('Consumer pagination is not expected in this test.');
            }

            public function listVisible(int $productId, VariantVisibleListCriteriaDTO $criteria): array
            {
                return $this->list;
            }

            public function paginateForManagement(int $productId, VariantListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
            {
                throw new \LogicException('paginateForManagement is not expected in this test.');
            }

            public function isProductVisible(int $productId): bool
            {
                return false;
            }

            public function listActiveOptionIds(int $productId): array
            {
                return [];
            }

            public function findActiveValueOptionIds(int $productId, array $valueIds): array
            {
                return [];
            }

            public function findActiveByFingerprint(int $productId, string $fingerprint): ?VariantStateDTO
            {
                return null;
            }
        };
    }

    private static function consumer(VariantQueryReaderInterface $reader): VariantConsumerService
    {
        return new VariantConsumerService($reader);
    }

    private static function service(VariantQueryReaderInterface $reader): VariantManagementService
    {
        $variants = new class implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                throw new \LogicException('createEmptyComposition is not expected in this test.');
            }
        };
        $transactions = new class implements SavepointTransactionRunnerInterface {
            public function run(callable $callback): mixed
            {
                return $callback();
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

        return new VariantManagementService($reader, $variants, new UnusedProductConfigurationRepository(), $transactions, $clock);
    }
}
