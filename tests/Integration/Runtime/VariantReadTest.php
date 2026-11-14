<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerServiceInterface;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantCompositionPairDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
use Throwable;

/**
 * Variant reads against real MySQL: consumer visibility is evaluated by the database, and
 * management reads filter only by status and soft-deletion state.
 */
final class VariantReadTest extends RuntimeTestCase
{
    private const string TS = '2026-01-01 00:00:00';

    public function testTheSimpleProductVariantIsHiddenFromConsumersUntilTheProductIsActive(): void
    {
        $created = $this->productManagementService()->create(new CreateProductCommand('SIMPLE'));
        $variants = $this->variantManagementService();
        $consumerVariants = $this->variantConsumerService();

        self::assertTrue($consumerVariants->listByProductId($created->productId)->isEmpty(), 'An inactive Product hides its Variants.');
        try {
            $consumerVariants->getById($created->variantId);
            self::fail('The Variant of an inactive Product is not consumer-visible.');
        } catch (VariantNotFoundException) {
        }

        $managed = $variants->getByIdForManagement($created->variantId);
        self::assertSame($created->productId, $managed->productId);
        self::assertSame([], $managed->composition);
        self::assertSame(VariantStatusEnum::ACTIVE, $managed->status);
        self::assertNull($managed->sku);
        self::assertSame('UTC', $managed->createdAt->getTimezone()->getName());

        $this->execute("UPDATE maa_product_products SET status = 'active' WHERE id = :i", ['i' => $created->productId]);
        $visible = $consumerVariants->getById($created->variantId);
        self::assertSame($created->variantId, $visible->id);
        self::assertCount(1, $consumerVariants->listByProductId($created->productId));
    }

    public function testAConfigurableVariantIsVisibleOnlyWithExactActiveCoverage(): void
    {
        $product = $this->activeProduct('CFG');
        $color = $this->option($product, 'color', 'active', 'Color');
        $size = $this->option($product, 'size', 'active', 'Size');
        $red = $this->value($color, 'red', 'active', 'Red');
        $medium = $this->value($size, 'm', 'active', 'Medium');
        // blue stays inactive: an active Value represented by no valid Variant would hide the whole Product (the product-wide rule).
        $blue = $this->value($color, 'blue', 'inactive', 'Blue');

        $full = $this->variant($product, 'full', 1, [[$size, $medium], [$color, $red]]);
        $partial = $this->variant($product, 'partial', 2, [[$color, $blue]]);
        $empty = $this->variant($product, 'empty', 3, []);

        $service = $this->variantManagementService();
        $consumer = $this->variantConsumerService();
        $visible = $consumer->listByProductId($product);

        self::assertSame([$full], array_map(static fn(VariantDTO $v): int => $v->id, $visible->items()));
        self::assertSame(
            [[$color, $red], [$size, $medium]],
            array_map(static fn(VariantCompositionPairDTO $pair): array => [$pair->optionId, $pair->optionValueId], $visible->items()[0]->composition),
            'The composition is ordered by numeric Option identity.',
        );
        self::assertSame($partial, $service->getByIdForManagement($partial)->id, 'Management reads ignore visibility.');
        self::assertSame($empty, $service->getByIdForManagement($empty)->id);
        foreach ([$partial, $empty] as $hidden) {
            $this->assertNotVisible($consumer, $hidden);
        }
    }

    public function testAnInactiveDeletedOrUnlabelledOptionOrValueHidesTheVariant(): void
    {
        $product = $this->activeProduct('CFG2');
        $color = $this->option($product, 'color', 'active', 'Color');
        $red = $this->value($color, 'red', 'active', 'Red');
        $variant = $this->variant($product, 'v', 1, [[$color, $red]]);
        $service = $this->variantConsumerService();
        self::assertSame($variant, $service->getById($variant)->id);

        $this->execute("UPDATE maa_product_option_values SET status = 'inactive' WHERE id = :i", ['i' => $red]);
        $this->assertNotVisible($service, $variant);
        $this->execute("UPDATE maa_product_option_values SET status = 'active' WHERE id = :i", ['i' => $red]);
        self::assertSame($variant, $service->getById($variant)->id);

        $this->execute('UPDATE maa_product_option_values SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $red]);
        $this->assertNotVisible($service, $variant);
        $this->execute('UPDATE maa_product_option_values SET deleted_at = NULL WHERE id = :i', ['i' => $red]);

        $this->execute('UPDATE maa_product_option_value_contents SET deleted_at = :t WHERE option_value_id = :i', ['t' => self::TS, 'i' => $red]);
        $this->assertNotVisible($service, $variant, 'A Value without a usable label hides the Variant.');
        $this->execute('UPDATE maa_product_option_value_contents SET deleted_at = NULL WHERE option_value_id = :i', ['i' => $red]);

        $this->execute("UPDATE maa_product_options SET status = 'inactive' WHERE id = :i", ['i' => $color]);
        $this->assertNotVisible($service, $variant, 'An inactive Option hides the Variant.');
        $this->execute("UPDATE maa_product_options SET status = 'active' WHERE id = :i", ['i' => $color]);

        $this->execute('UPDATE maa_product_option_contents SET deleted_at = :t WHERE option_id = :i', ['t' => self::TS, 'i' => $color]);
        $this->assertNotVisible($service, $variant, 'An Option without a usable label hides the Variant.');
    }

    public function testAnInactiveOrDeletedVariantOrProductHidesTheVariantAndTheList(): void
    {
        $created = $this->productManagementService()->create(new CreateProductCommand('LIFE'));
        $this->execute("UPDATE maa_product_products SET status = 'active' WHERE id = :i", ['i' => $created->productId]);
        $service = $this->variantConsumerService();
        self::assertSame($created->variantId, $service->getById($created->variantId)->id);

        $this->execute("UPDATE maa_product_variants SET status = 'inactive' WHERE id = :i", ['i' => $created->variantId]);
        $this->assertNotVisible($service, $created->variantId);
        $this->execute("UPDATE maa_product_variants SET status = 'active' WHERE id = :i", ['i' => $created->variantId]);

        $this->execute('UPDATE maa_product_variants SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $created->variantId]);
        $this->assertNotVisible($service, $created->variantId);
        $this->execute('UPDATE maa_product_variants SET deleted_at = NULL WHERE id = :i', ['i' => $created->variantId]);

        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $created->productId]);
        $this->assertNotVisible($service, $created->variantId);
        self::assertTrue($service->listByProductId($created->productId)->isEmpty());
        self::assertTrue($service->listByProductId(999999)->isEmpty(), 'A missing Product yields an empty list, not an error.');
    }

    public function testManagementListFiltersByStatusAndDeletedStateInStableOrder(): void
    {
        $product = $this->activeProduct('MGMT');
        $first = $this->variant($product, 'a', 2, []);
        $second = $this->variant($product, 'b', 1, [], 'inactive');
        $third = $this->variant($product, 'c', 1, [], 'active', self::TS);
        $service = $this->variantManagementService();
        $consumer = $this->variantConsumerService();

        $ids = static fn(VariantCollectionDTO $collection): array => array_map(static fn(VariantDTO $v): int => $v->id, $collection->items());

        self::assertSame([$second, $first], $ids($service->listByProductIdForManagement($product)));
        self::assertSame([$second], $ids($service->listByProductIdForManagement($product, new VariantListCriteriaDTO(VariantStatusEnum::INACTIVE))));
        self::assertSame([$third], $ids($service->listByProductIdForManagement($product, new VariantListCriteriaDTO(null, ProductDeletedStateEnum::DELETED_ONLY))));
        self::assertSame([$second, $third, $first], $ids($service->listByProductIdForManagement($product, new VariantListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED))));
        self::assertSame([$second], $ids($service->listByProductIdForManagement($product, new VariantListCriteriaDTO(null, ProductDeletedStateEnum::INCLUDE_DELETED, 1))));
        self::assertCount(1, $consumer->listByProductId($product, new VariantVisibleListCriteriaDTO(1)));

        $this->expectException(VariantNotFoundException::class);
        $service->getByIdForManagement($third);
    }

    /**
     * The product-wide rule: the consumer reads apply the whole Product configuration, not only the
     * validity of the single Variant. While an active Value of an active Option is represented by no
     * active valid Variant, every Variant of the Product is hidden from the single get and from the
     * list alike; management reads keep showing the stored state; and the reads return the correct
     * result again once every active Value is represented.
     */
    public function testAnUnrepresentedActiveValueHidesEveryVariantOfTheProductFromConsumers(): void
    {
        $product = $this->activeProduct('UNREPRESENTED');
        $color = $this->option($product, 'color', 'active', 'Color');
        $red = $this->value($color, 'red', 'active', 'Red');
        $blue = $this->value($color, 'blue', 'active', 'Blue');
        $redVariant = $this->variant($product, 'red', 1, [[$color, $red]]);
        $service = $this->variantManagementService();
        $consumer = $this->variantConsumerService();

        $this->assertNotVisible($consumer, $redVariant, 'The Product has an active Value (blue) that no active Variant represents, so the get hides its Variants.');
        self::assertTrue($consumer->listByProductId($product)->isEmpty(), 'The consumer list hides the Variants of the same Product for the same reason.');
        self::assertSame($redVariant, $service->getByIdForManagement($redVariant)->id, 'Management reads show the stored state.');
        self::assertSame([$redVariant], array_map(static fn(VariantDTO $v): int => $v->id, $service->listByProductIdForManagement($product)->items()));

        $blueVariant = $this->variant($product, 'blue', 2, [[$color, $blue]]);
        self::assertSame($redVariant, $consumer->getById($redVariant)->id, 'Once every active Value is represented, the Variants are visible again.');
        self::assertSame([$redVariant, $blueVariant], array_map(static fn(VariantDTO $v): int => $v->id, $consumer->listByProductId($product)->items()));

        $this->execute('DELETE FROM maa_product_variant_option_values WHERE variant_id = :v', ['v' => $blueVariant]);
        $this->execute('DELETE FROM maa_product_variants WHERE id = :v', ['v' => $blueVariant]);
        $this->assertNotVisible($consumer, $redVariant, 'Removing the representing Variant hides the Product again.');
        $this->execute("UPDATE maa_product_option_values SET status = 'inactive' WHERE id = :i", ['i' => $blue]);
        self::assertSame($redVariant, $consumer->getById($redVariant)->id, 'Deactivating the unrepresented Value restores the correct result.');
        self::assertSame([$redVariant], array_map(static fn(VariantDTO $v): int => $v->id, $consumer->listByProductId($product)->items()));
    }

    public function testInvalidIdentitiesAndLimitsAreRejected(): void
    {
        $service = $this->variantManagementService();
        $consumer = $this->variantConsumerService();

        foreach ([
            static fn() => $consumer->getById(0),
            static fn() => $service->getByIdForManagement(-1),
            static fn() => $consumer->listByProductId(0),
            static fn() => $service->listByProductIdForManagement(0),
            static fn() => new VariantVisibleListCriteriaDTO(0),
            static fn() => new VariantListCriteriaDTO(null, ProductDeletedStateEnum::NON_DELETED, 101),
        ] as $call) {
            try {
                $call();
                self::fail('The call must be rejected.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(ProductInvalidArgumentException::class, $caught);
            }
        }
        $this->expectException(VariantNotFoundException::class);
        $consumer->getById(999999);
    }

    private function assertNotVisible(VariantConsumerServiceInterface $service, int $variantId, string $message = ''): void
    {
        try {
            $service->getById($variantId);
            self::fail($message === '' ? 'The Variant must not be consumer-visible.' : $message);
        } catch (VariantNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function activeProduct(string $code): int
    {
        $created = $this->productManagementService()->create(new CreateProductCommand($code));
        $this->execute("UPDATE maa_product_products SET status = 'active' WHERE id = :i", ['i' => $created->productId]);
        // The Variant created with the Product is the empty composition; remove it so each test controls the graph.
        $this->execute('DELETE FROM maa_product_variants WHERE id = :i', ['i' => $created->variantId]);

        return $created->productId;
    }

    private function option(int $product, string $code, string $status, string $label): int
    {
        $id = $this->insert(
            'INSERT INTO maa_product_options (product_id, code, status, display_order, created_at, updated_at) VALUES (:p, :c, :s, :o, :t, :u)',
            ['p' => $product, 'c' => $code, 's' => $status, 'o' => $this->nextOrder()],
        );
        $this->execute(
            'INSERT INTO maa_product_option_contents (option_id, language_code, name, created_at, updated_at) VALUES (:i, NULL, :n, :t, :u)',
            ['i' => $id, 'n' => $label, 't' => self::TS, 'u' => self::TS],
        );

        return $id;
    }

    private function value(int $option, string $code, string $status, string $label): int
    {
        $id = $this->insert(
            'INSERT INTO maa_product_option_values (option_id, code, status, display_order, created_at, updated_at) VALUES (:p, :c, :s, :o, :t, :u)',
            ['p' => $option, 'c' => $code, 's' => $status, 'o' => $this->nextOrder()],
        );
        $this->execute(
            'INSERT INTO maa_product_option_value_contents (option_value_id, language_code, name, created_at, updated_at) VALUES (:i, NULL, :n, :t, :u)',
            ['i' => $id, 'n' => $label, 't' => self::TS, 'u' => self::TS],
        );

        return $id;
    }

    /**
     * @param list<array{int, int}> $pairs (option id, option value id)
     */
    private function variant(int $product, string $key, int $order, array $pairs, string $status = 'active', ?string $deletedAt = null): int
    {
        $id = $this->insert(
            'INSERT INTO maa_product_variants (product_id, composition_fingerprint, status, display_order, created_at, updated_at, deleted_at) '
            . 'VALUES (:p, :f, :s, :o, :t, :u, :d)',
            ['p' => $product, 'f' => hash('sha256', 'variant|' . $product . '|' . $key), 's' => $status, 'o' => $order, 'd' => $deletedAt],
        );
        foreach ($pairs as [$option, $value]) {
            $this->execute(
                'INSERT INTO maa_product_variant_option_values (product_id, variant_id, option_id, option_value_id) VALUES (:p, :v, :o, :ov)',
                ['p' => $product, 'v' => $id, 'o' => $option, 'ov' => $value],
            );
        }

        return $id;
    }

    /** @param array<string, int|string|null> $params */
    private function insert(string $sql, array $params): int
    {
        $this->execute($sql, $params + ['t' => self::TS, 'u' => self::TS]);

        return (int) self::$pdo->lastInsertId();
    }

    private int $order = 0;

    private function nextOrder(): int
    {
        return ++$this->order;
    }
}
