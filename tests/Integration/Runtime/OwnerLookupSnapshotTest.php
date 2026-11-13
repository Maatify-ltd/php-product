<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises owner discovery after the Host has established an older InnoDB read view. */
final class OwnerLookupSnapshotTest extends RuntimeTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function paths(): iterable
    {
        yield 'Option mutation' => ['option', 'maa_product_options'];
        yield 'Option Value create' => ['value-create', 'maa_product_option_values'];
        yield 'Option Value mutation' => ['value', 'maa_product_option_values'];
        yield 'Variant mutation' => ['variant', 'maa_product_variants'];
        yield 'Product Content create' => ['product-content-create', 'maa_product_product_contents'];
        yield 'Option Content create' => ['option-content-create', 'maa_product_option_contents'];
        yield 'Value Content create' => ['value-content-create', 'maa_product_option_value_contents'];
        yield 'Product Content mutation' => ['product-content', 'maa_product_product_contents'];
        yield 'Option Content mutation' => ['option-content', 'maa_product_option_contents'];
        yield 'Value Content mutation' => ['value-content', 'maa_product_option_value_contents'];
        yield 'Image Assignment mutation' => ['image', 'maa_product_image_assignments'];
    }

    #[DataProvider('paths')]
    public function testMutationSeesNewlyCommittedIdentityWithoutEndingHostTransaction(string $path, string $table): void
    {
        $writer = self::connect(self::$database);
        $lookup = self::connect(self::$database);
        $clock = self::clock();
        $host = self::$pdo;

        $host->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $host->beginTransaction();
        self::assertSame(0, self::rowCount('maa_product_products'));

        $created = ProductFactory::createProductManagementService($writer, $clock)->create(new CreateProductCommand('LATER'));
        $product = $created->productId;
        $option = ProductFactory::createOptionManagementService($writer, $clock)
            ->create(new CreateOptionCommand($product, 'later'))->optionId;
        $value = ProductFactory::createOptionValueManagementService($writer, $clock)
            ->create(new CreateOptionValueCommand($option, 'later'))->optionValueId;
        self::assertSame(0, self::rowCount('maa_product_products'), 'The Host still has its original read view.');
        self::assertFalse($writer->inTransaction());

        $productContent = ProductFactory::createProductContentManagementService($host, $clock, $lookup);
        $optionContent = ProductFactory::createOptionContentManagementService($host, $clock, $lookup);
        $valueContent = ProductFactory::createOptionValueContentManagementService($host, $clock, $lookup);
        $productLabel = new CreateProductContentCommand($product, null, 'Name');
        $optionLabel = new CreateOptionContentCommand($option, null, 'Label');
        $valueLabel = new CreateOptionValueContentCommand($value, null, 'Label');
        $values = ProductFactory::createOptionValueManagementService($host, $clock, $lookup);
        $images = ProductFactory::createImageAssignmentManagementService($host, $clock, $lookup);

        switch ($path) {
            case 'option':
                ProductFactory::createOptionManagementService($host, $clock, $lookup)->softDelete($option);
                break;
            case 'value-create':
                $values->create(new CreateOptionValueCommand($option, 'host'));
                break;
            case 'value':
                $values->softDelete($value);
                break;
            case 'variant':
                ProductFactory::createVariantManagementService($host, $clock, $lookup)->updateIdentifiers(
                    new UpdateVariantIdentifiersCommand($created->variantId, VariantIdentifierChange::set('HOST'), VariantIdentifierChange::unchanged()),
                );
                break;
            case 'product-content-create':
                $productContent->create($productLabel);
                break;
            case 'option-content-create':
                $optionContent->create($optionLabel);
                break;
            case 'value-content-create':
                $valueContent->create($valueLabel);
                break;
            case 'product-content':
                $productContent->softDelete(ProductFactory::createProductContentManagementService($writer, $clock)->create($productLabel)->contentId);
                break;
            case 'option-content':
                $optionContent->softDelete(ProductFactory::createOptionContentManagementService($writer, $clock)->create($optionLabel)->contentId);
                break;
            case 'value-content':
                $valueContent->softDelete(ProductFactory::createOptionValueContentManagementService($writer, $clock)->create($valueLabel)->contentId);
                break;
            case 'image':
                $assignment = ProductFactory::createImageAssignmentManagementService($writer, $clock)
                    ->create(new CreateImageAssignmentCommand($product, $created->variantId, 1))->assignmentId;
                $images->setDefault($assignment);
                break;
        }

        $predicate = match ($path) {
            'option', 'value', 'product-content', 'option-content', 'value-content' => 'deleted_at IS NOT NULL',
            'value-create' => "code = 'host'",
            'variant' => "sku = 'HOST'",
            'image' => 'is_default = 1',
            default => 'id > 0',
        };
        self::assertSame(1, self::rowCount($table, $predicate));
        self::assertTrue($host->inTransaction());
        self::assertFalse($lookup->inTransaction());
        $host->rollBack();
        self::assertSame(0, self::rowCount($table, $predicate), 'The Host can roll back the package write.');
    }

    public function testLookupKeepsUncommittedHostIdentitiesVisible(): void
    {
        $lookup = self::connect(self::$database);
        self::$pdo->beginTransaction();
        $product = $this->namedProduct('HOST')->productId;
        $options = ProductFactory::createOptionManagementService(self::$pdo, self::clock(), $lookup);
        $option = $options->create(new CreateOptionCommand($product, 'host'))->optionId;
        $options->softDelete($option);
        self::assertSame(1, self::rowCount('maa_product_options', 'deleted_at IS NOT NULL'));
        self::assertTrue(self::$pdo->inTransaction());
        self::$pdo->rollBack();
        self::assertSame(0, self::rowCount('maa_product_options'));
    }
}
