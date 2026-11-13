<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;

/** Verifies visible counts and continuation beyond the legacy list limit. */
final class ConsumerPaginationTest extends RuntimeTestCase
{
    public function testOptionsArePagedWithoutCountingInactiveOptions(): void
    {
        $product = $this->namedProduct('PAGED-OPTIONS')->productId;
        $options = [];
        $composition = [];
        for ($i = 1; $i <= 101; ++$i) {
            $option = $this->preparedOption($product, 'option-' . $i, 'Option ' . $i);
            $value = $this->preparedValue($option, 'value', 'Value');
            $options[$option] = [$value];
            $composition[] = [$option, $value];
        }
        $this->preparedOption($product, 'hidden', 'Hidden');
        $this->productManagementService()->reconfigure($this->reconfiguration($product, $options, [[$composition]]));
        $this->activateProduct($product);

        $reader = $this->optionConsumerService();
        $this->assertPages(
            $reader->listVisibleByProductId($product)->items(),
            $reader->paginateVisibleByProductId($product, new PageRequest(1, 100, 'id', 'DESC')),
            $reader->paginateVisibleByProductId($product, new PageRequest(2, 100)),
            array_keys($options),
        );
    }

    public function testValuesAndVariantsArePagedWithoutCountingInactiveRows(): void
    {
        $created = $this->namedProduct('PAGED-VALUES');
        $product = $created->productId;
        $option = $this->preparedOption($product, 'option', 'Option');
        $values = [];
        $targets = [];
        for ($i = 1; $i <= 101; ++$i) {
            $value = $this->preparedValue($option, 'value-' . $i, 'Value ' . $i);
            $values[] = $value;
            $targets[] = [[[$option, $value]]];
        }
        $this->preparedValue($option, 'hidden', 'Hidden');
        $variants = $this->productManagementService()
            ->reconfigure($this->reconfiguration($product, [$option => $values], $targets))->createdVariantIds;
        $this->activateProduct($product);

        $valueReader = $this->optionValueConsumerService();
        $variantReader = $this->variantConsumerService();
        $this->assertPages(
            $valueReader->listVisibleByOptionId($option)->items(),
            $valueReader->paginateVisibleByOptionId($option, new PageRequest(1, 100)),
            $valueReader->paginateVisibleByOptionId($option, new PageRequest(2, 100)),
            $values,
        );
        $this->assertPages(
            $variantReader->listByProductId($product)->items(),
            $variantReader->paginateByProductId($product, new PageRequest(1, 100)),
            $variantReader->paginateByProductId($product, new PageRequest(2, 100)),
            $variants,
        );
    }

    public function testImagesArePagedWithinOneExactScopeWithoutCountingDeletedRows(): void
    {
        $product = $this->namedProduct('PAGED-IMAGES')->productId;
        $this->activateProduct($product);
        $writer = $this->imageAssignmentManagementService();
        $deleted = $writer->create(new CreateImageAssignmentCommand($product, null, 500))->assignmentId;
        $writer->softDelete($deleted);
        $writer->create(new CreateImageAssignmentCommand($product, null, 501, null, 'en'));
        $ids = [];
        for ($i = 1; $i <= 101; ++$i) {
            $ids[] = $writer->create(new CreateImageAssignmentCommand($product, null, $i))->assignmentId;
        }
        $reader = $this->imageAssignmentConsumerService();
        $criteria = new ImageAssignmentExactScopeCriteriaDTO($product, null, null, null, null);
        $this->assertPages(
            $reader->listVisibleByExactScope($criteria)->items(),
            $reader->paginateVisibleByExactScope($criteria, new PageRequest(1, 100)),
            $reader->paginateVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($product, null, null, null, null, maxResults: 1), new PageRequest(2, 100)),
            $ids,
        );
    }

    /**
     * @template T of object{id: int}
     * @param list<T> $legacy
     * @param PageResultDTO<T> $first
     * @param PageResultDTO<T> $second
     * @param list<int> $expected
     */
    private function assertPages(array $legacy, PageResultDTO $first, PageResultDTO $second, array $expected): void
    {
        $ids = static fn(array $rows): array => array_column($rows, 'id');
        self::assertSame(array_slice($expected, 0, 100), $ids($legacy));
        self::assertSame($ids($legacy), $ids($first->data));
        self::assertSame(array_slice($expected, 100), $ids($second->data));
        self::assertSame([101, 101, 2, true], [$first->total, $first->filtered, $first->totalPages, $first->hasNext]);
        self::assertSame([101, 101, 2, false], [$second->total, $second->filtered, $second->totalPages, $second->hasNext]);
    }
}
