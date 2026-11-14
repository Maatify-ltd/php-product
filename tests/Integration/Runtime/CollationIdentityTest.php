<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentAlreadyExistsException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageRole\Exception\ImageRoleKeyAlreadyExistsException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Option\Exception\OptionCodeAlreadyExistsException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Exception\OptionValueCodeAlreadyExistsException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Exception\VariantBarcodeAlreadyExistsException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use PDOException;
use Throwable;

/**
 * Collation evidence for every text identity: under `utf8mb4_unicode_ci` (a PAD SPACE
 * collation in MySQL 8.0), values that differ only by accents or by trailing spaces are the same
 * identity. The package performs no PHP pre-check, so the database unique key decides, and the
 * collision surfaces as the family's typed conflict with the driver error as `previous`; the
 * business-key reads match the same way. Each value is stored exactly as supplied.
 */
final class CollationIdentityTest extends RuntimeTestCase
{
    public function testProductCodesCompareAccentAndTrailingSpaceInsensitively(): void
    {
        $products = $this->productManagementService();
        $cafe = $products->create(new CreateProductCommand('Café'))->productId;
        $this->assertTypedConflict(ProductCodeAlreadyExistsException::class, fn() => $products->create(new CreateProductCommand('Cafe')));
        $padded = $products->create(new CreateProductCommand('TEA '))->productId;
        $this->assertTypedConflict(ProductCodeAlreadyExistsException::class, fn() => $products->create(new CreateProductCommand('TEA')));

        self::assertSame('Café', $products->getByIdForManagement($cafe)->code, 'Stored verbatim.');
        self::assertSame('TEA ', $products->getByIdForManagement($padded)->code, 'A trailing space is stored.');
        self::assertSame($cafe, $products->getByCodeForManagement('CAFE')->id, 'Lookup ignores accents and case.');
        self::assertSame($padded, $products->getByCodeForManagement('tea')->id, 'Lookup ignores trailing spaces.');
    }

    public function testOptionAndValueCodesCompareAccentAndTrailingSpaceInsensitively(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $options = $this->optionManagementService();
        $optionId = $options->create(new CreateOptionCommand($productId, 'Couleur'))->optionId;
        $this->assertTypedConflict(OptionCodeAlreadyExistsException::class, fn() => $options->create(new CreateOptionCommand($productId, 'Coüleur')));
        $options->create(new CreateOptionCommand($productId, 'size '));
        $this->assertTypedConflict(OptionCodeAlreadyExistsException::class, fn() => $options->create(new CreateOptionCommand($productId, 'size')));

        $values = $this->optionValueManagementService();
        $values->create(new CreateOptionValueCommand($optionId, 'crème'));
        $this->assertTypedConflict(OptionValueCodeAlreadyExistsException::class, fn() => $values->create(new CreateOptionValueCommand($optionId, 'creme')));
        $values->create(new CreateOptionValueCommand($optionId, 'red '));
        $this->assertTypedConflict(OptionValueCodeAlreadyExistsException::class, fn() => $values->create(new CreateOptionValueCommand($optionId, 'red')));
    }

    public function testRoleKeysCompareAccentAndTrailingSpaceInsensitively(): void
    {
        $roles = $this->imageRoleManagementService();
        $hero = $roles->create(new CreateImageRoleCommand('héro'))->roleId;
        $this->assertTypedConflict(ImageRoleKeyAlreadyExistsException::class, fn() => $roles->create(new CreateImageRoleCommand('hero')));
        $thumb = $roles->create(new CreateImageRoleCommand('thumb '))->roleId;
        $this->assertTypedConflict(ImageRoleKeyAlreadyExistsException::class, fn() => $roles->create(new CreateImageRoleCommand('thumb')));

        self::assertSame($hero, $roles->getByKeyForManagement('HERO')->id);
        self::assertSame($thumb, $roles->getByKeyForManagement('thumb')->id);
        self::assertSame('thumb ', $roles->getByKeyForManagement('thumb')->roleKey);
    }

    public function testSkuAndBarcodeCompareAccentAndTrailingSpaceInsensitively(): void
    {
        $a = $this->productManagementService()->create(new CreateProductCommand('A'))->variantId;
        $b = $this->productManagementService()->create(new CreateProductCommand('B'))->variantId;
        $variants = $this->variantManagementService();
        $set = static fn(int $variant, ?string $sku, ?string $barcode): UpdateVariantIdentifiersCommand => new UpdateVariantIdentifiersCommand(
            $variant,
            $sku === null ? VariantIdentifierChange::unchanged() : VariantIdentifierChange::set($sku),
            $barcode === null ? VariantIdentifierChange::unchanged() : VariantIdentifierChange::set($barcode),
        );

        $variants->updateIdentifiers($set($a, 'SKU-É', '0012 '));
        $this->assertTypedConflict(VariantSkuAlreadyExistsException::class, fn() => $variants->updateIdentifiers($set($b, 'sku-e', null)));
        $this->assertTypedConflict(VariantBarcodeAlreadyExistsException::class, fn() => $variants->updateIdentifiers($set($b, null, '0012')));
        $variants->updateIdentifiers($set($a, 'SKU-1 ', null));
        $this->assertTypedConflict(VariantSkuAlreadyExistsException::class, fn() => $variants->updateIdentifiers($set($b, 'SKU-1', null)));
        $variants->updateIdentifiers($set($a, null, 'Ü-1'));
        $this->assertTypedConflict(VariantBarcodeAlreadyExistsException::class, fn() => $variants->updateIdentifiers($set($b, null, 'u-1')));
        self::assertSame(['SKU-1 ', 'Ü-1'], [$variants->getByIdForManagement($a)->sku, $variants->getByIdForManagement($a)->barcode]);
    }

    public function testContentLanguageAndAssignmentScopesCompareAccentAndTrailingSpaceInsensitively(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $content = $this->productContentManagementService();
        $content->create(new CreateProductContentCommand($productId, 'fé', 'Name'));
        $this->assertTypedConflict(ProductContentAlreadyExistsException::class, fn() => $content->create(new CreateProductContentCommand($productId, 'fe', 'Name')));
        $content->create(new CreateProductContentCommand($productId, 'en ', 'Name'));
        $this->assertTypedConflict(ProductContentAlreadyExistsException::class, fn() => $content->create(new CreateProductContentCommand($productId, 'en', 'Name')));

        $assignments = $this->imageAssignmentManagementService();
        $assignments->create(new CreateImageAssignmentCommand($productId, null, 1, null, 'fé', 'wéb'));
        $this->assertTypedConflict(ImageAssignmentAlreadyExistsException::class, fn() => $assignments->create(new CreateImageAssignmentCommand($productId, null, 1, null, 'fe', 'wéb')), 'Language accents.');
        $this->assertTypedConflict(ImageAssignmentAlreadyExistsException::class, fn() => $assignments->create(new CreateImageAssignmentCommand($productId, null, 1, null, 'fé', 'web')), 'Platform accents.');
        $assignments->create(new CreateImageAssignmentCommand($productId, null, 2, null, 'en ', 'app '));
        $this->assertTypedConflict(ImageAssignmentAlreadyExistsException::class, fn() => $assignments->create(new CreateImageAssignmentCommand($productId, null, 2, null, 'en', 'app ')), 'Language trailing space.');
        $this->assertTypedConflict(ImageAssignmentAlreadyExistsException::class, fn() => $assignments->create(new CreateImageAssignmentCommand($productId, null, 2, null, 'en ', 'app')), 'Platform trailing space.');
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertTypedConflict(string $expected, callable $call, string $message = ''): void
    {
        $caught = $this->assertThrows($expected, $call, $message);
        $previous = $caught->getPrevious();
        self::assertInstanceOf(PDOException::class, $previous, 'The conflict carries the driver error.');
        $code = $previous->errorInfo[1] ?? null;
        self::assertTrue(is_int($code) || is_string($code));
        self::assertSame(1062, (int) $code, 'The database unique key decided.');
    }
}
