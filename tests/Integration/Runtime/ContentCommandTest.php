<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\DTO\ProductContentDTO;
use Maatify\Product\Content\Exception\OptionContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionValueContentAlreadyExistsException;
use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;
use Maatify\Product\Content\Exception\ProductContentNotFoundException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\UpdateProductContentCommand;
use Maatify\Product\Content\Management\Criteria\ProductContentListCriteriaDTO;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use PDOException;

/**
 * Product, Option, and Option Value Content against real MySQL: the `(owner, language)` identity
 * including the unlocalized `NULL` record and soft-deleted history, full-replacement updates that
 * write nothing when unchanged, the required-label invariants (`INV-05`, `INV-06`) on soft delete,
 * the owner-chain rules, exact language selectors without fallback, and the consumer visibility rule.
 */
final class ContentCommandTest extends RuntimeTestCase
{
    public function testEveryOwnerTakesAnUnlocalizedAndALocalizedRecordOncePerIdentity(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $products = $this->productContentManagementService();
        $unlocalized = $products->create(new CreateProductContentCommand($productId, null, '  Shirt '))->contentId;
        $english = $products->create(new CreateProductContentCommand($productId, 'en', 'Shirt EN', 'Short', ''))->contentId;

        $dto = $products->getByIdForManagement($english);
        self::assertSame([$productId, 'en', 'Shirt EN', 'Short', ''], [$dto->productId, $dto->languageCode, $dto->name, $dto->shortDescription, $dto->description], 'Values are stored as supplied, an empty description included.');
        self::assertSame('  Shirt ', $products->getByIdForManagement($unlocalized)->name, 'A name is never trimmed.');
        self::assertNull($products->getByIdForManagement($unlocalized)->languageCode);

        $conflict = $this->assertThrows(ProductContentAlreadyExistsException::class, fn() => $products->create(new CreateProductContentCommand($productId, 'EN', 'Other')), 'Language case variants are one identity.');
        self::assertInstanceOf(PDOException::class, $conflict->getPrevious());
        $this->assertThrows(ProductContentAlreadyExistsException::class, fn() => $products->create(new CreateProductContentCommand($productId, null, 'Other')), 'There is one unlocalized record.');

        $optionId = $this->optionManagementService()->create(new CreateOptionCommand($productId, 'color'))->optionId;
        $valueId = $this->optionValueManagementService()->create(new CreateOptionValueCommand($optionId, 'red'))->optionValueId;
        $options = $this->optionContentManagementService();
        $values = $this->optionValueContentManagementService();
        $options->create(new CreateOptionContentCommand($optionId, null, 'Color'));
        $arabic = $options->create(new CreateOptionContentCommand($optionId, 'ar', 'لون'))->contentId;
        self::assertSame('لون', $options->getByIdForManagement($arabic)->name);
        $this->assertThrows(OptionContentAlreadyExistsException::class, fn() => $options->create(new CreateOptionContentCommand($optionId, 'AR', 'x')));
        $values->create(new CreateOptionValueContentCommand($valueId, null, 'Red'));
        $values->create(new CreateOptionValueContentCommand($valueId, 'en', 'Red'));
        $this->assertThrows(OptionValueContentAlreadyExistsException::class, fn() => $values->create(new CreateOptionValueContentCommand($valueId, null, 'x')));

        $this->assertThrows(ProductNotFoundException::class, fn() => $products->create(new CreateProductContentCommand(999999, null, 'x')));
        $this->assertThrows(OptionNotFoundException::class, fn() => $options->create(new CreateOptionContentCommand(999999, null, 'x')));
        $this->assertThrows(OptionValueNotFoundException::class, fn() => $values->create(new CreateOptionValueContentCommand(999999, null, 'x')));

        $this->optionManagementService()->softDelete($optionId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->create(new CreateOptionContentCommand($optionId, 'fr', 'x')), 'A deleted Option takes no new label.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->create(new CreateOptionValueContentCommand($valueId, 'fr', 'x')), 'A Value under a deleted Option takes no new label.');
        $this->productManagementService()->softDelete($productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->create(new CreateProductContentCommand($productId, 'fr', 'x')));
        self::assertSame(2, self::rowCount('maa_product_product_contents'));
        self::assertSame(2, self::rowCount('maa_product_option_contents'));
        self::assertSame(2, self::rowCount('maa_product_option_value_contents'));
    }

    public function testUpdateReplacesTheFieldsAndAnUnchangedRequestWritesNothing(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $products = $this->productContentManagementService();
        $contentId = $products->create(new CreateProductContentCommand($productId, 'en', 'Shirt', 'Short', 'Long'))->contentId;

        $products->update(new UpdateProductContentCommand($contentId, 'Shirt 2', null, 'Longer'));
        $dto = $products->getByIdForManagement($contentId);
        self::assertSame(['en', 'Shirt 2', null, 'Longer'], [$dto->languageCode, $dto->name, $dto->shortDescription, $dto->description], 'Every field is replaced; the language never changes.');

        $later = $this->productContentManagementService(self::laterClock());
        $this->assertNoWrite('maa_product_product_contents', fn() => $later->update(new UpdateProductContentCommand($contentId, 'Shirt 2', null, 'Longer')), 'An unchanged update writes nothing.');
        $later->update(new UpdateProductContentCommand($contentId, 'Shirt 2', '', 'Longer'));
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_product_contents WHERE id = :i', ['i' => $contentId]), 'An empty string differs from null and is written.');

        $optionId = $this->optionManagementService()->create(new CreateOptionCommand($productId, 'color'))->optionId;
        $valueId = $this->optionValueManagementService()->create(new CreateOptionValueCommand($optionId, 'red'))->optionValueId;
        $optionLabel = $this->optionContentManagementService()->create(new CreateOptionContentCommand($optionId, null, 'Color'))->contentId;
        $valueLabel = $this->optionValueContentManagementService()->create(new CreateOptionValueContentCommand($valueId, null, 'Red'))->contentId;
        $this->assertNoWrite('maa_product_option_contents', fn() => $this->optionContentManagementService(self::laterClock())->update(new UpdateOptionContentCommand($optionLabel, 'Color')), 'An unchanged label writes nothing.');
        $this->assertNoWrite('maa_product_option_value_contents', fn() => $this->optionValueContentManagementService(self::laterClock())->update(new UpdateOptionValueContentCommand($valueLabel, 'Red')), 'An unchanged label writes nothing.');
        $this->optionContentManagementService()->update(new UpdateOptionContentCommand($optionLabel, 'Colour'));
        self::assertSame('Colour', $this->optionContentManagementService()->getByIdForManagement($optionLabel)->name);

        $this->assertThrows(ProductContentNotFoundException::class, fn() => $products->update(new UpdateProductContentCommand(999999, 'x', null, null)));
        $products->softDelete($contentId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->update(new UpdateProductContentCommand($contentId, 'x', null, null)), 'A deleted record accepts only restore.');
        $this->optionManagementService()->softDelete($optionId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionContentManagementService()->update(new UpdateOptionContentCommand($optionLabel, 'x')), 'A record of a deleted owner cannot change.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionValueContentManagementService()->update(new UpdateOptionValueContentCommand($valueLabel, 'x')));
    }

    public function testSoftDeleteKeepsTheRequiredNamesAndRestoreReturnsTheOriginalIdentity(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $products = $this->productContentManagementService();
        $unlocalized = $products->create(new CreateProductContentCommand($productId, null, 'Shirt'))->contentId;
        $this->activateProduct($productId);

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->softDelete($unlocalized), 'The last name of an active Product stays (INV-06).');
        $english = $products->create(new CreateProductContentCommand($productId, 'en', 'Shirt'))->contentId;
        $products->softDelete($unlocalized);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->softDelete($english));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->softDelete($unlocalized), 'A deleted record cannot be deleted again.');
        $this->assertThrows(ProductContentAlreadyExistsException::class, fn() => $products->create(new CreateProductContentCommand($productId, null, 'Shirt')), 'A deleted identity stays reserved.');
        $products->restore($unlocalized);
        self::assertNull($products->getByIdForManagement($unlocalized)->deletedAt, 'Restore brings back the same identity.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->restore($unlocalized), 'A live record cannot be restored.');

        $this->productManagementService()->updateStatus($productId, ProductStatusEnum::INACTIVE);
        $products->softDelete($unlocalized);
        $products->softDelete($english);
        self::assertSame(0, self::rowCount('maa_product_product_contents', 'deleted_at IS NULL'), 'An inactive Product needs no name.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->activateProduct($productId));

        [$color, $red, $blue] = $this->configurableProduct($productId);
        $options = $this->optionContentManagementService();
        $values = $this->optionValueContentManagementService();
        $colorLabel = $this->labelIdOf('maa_product_option_contents', 'option_id', $color);
        $redLabel = $this->labelIdOf('maa_product_option_value_contents', 'option_value_id', $red);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->softDelete($colorLabel), 'The last label of an active Option stays (INV-05).');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->softDelete($redLabel), 'The last label of an active Value of an active Option stays (INV-05).');
        $options->create(new CreateOptionContentCommand($color, 'en', 'Color'));
        $options->softDelete($colorLabel);
        $options->restore($colorLabel);

        $size = $this->optionManagementService()->create(new CreateOptionCommand($productId, 'size'))->optionId;
        $sizeLabel = $options->create(new CreateOptionContentCommand($size, null, 'Size'))->contentId;
        $options->softDelete($sizeLabel);
        $green = $this->optionValueManagementService()->create(new CreateOptionValueCommand($color, 'green'))->optionValueId;
        $greenLabel = $values->create(new CreateOptionValueContentCommand($green, null, 'Green'))->contentId;
        $values->softDelete($greenLabel);
        self::assertNotNull($values->getByIdForManagement($greenLabel, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt, 'An inactive Option or Value may lose its last label.');

        $this->optionManagementService()->softDelete($size);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->restore($sizeLabel), 'Restore needs a live owner chain.');
        $this->optionValueManagementService()->softDelete($green);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->restore($greenLabel));
        self::assertSame(1, self::rowCount('maa_product_option_value_contents', 'deleted_at IS NULL AND option_value_id = ' . $blue));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $options->softDelete(0));
    }

    public function testManagementReadsSelectLanguagesExactlyAndPaginate(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $products = $this->productContentManagementService();
        $ids = [];
        foreach ([null, 'fr', 'ar', 'en'] as $language) {
            $ids[$language ?? 'null'] = $products->create(new CreateProductContentCommand($productId, $language, 'Name ' . ($language ?? '-')))->contentId;
        }
        $products->softDelete($ids['fr']);

        $languages = fn(ContentLanguageFilter $filter, ProductDeletedStateEnum $state = ProductDeletedStateEnum::NON_DELETED, int $max = 100): array => array_map(
            static fn(ProductContentDTO $dto): ?string => $dto->languageCode,
            $products->listForManagement(new ProductContentListCriteriaDTO($productId, $filter, $state, $max))->items(),
        );
        self::assertSame([null, 'ar', 'en'], $languages(ContentLanguageFilter::allLanguages()), 'Unlocalized first, then by language.');
        self::assertSame(['ar'], $languages(ContentLanguageFilter::exactLanguage('ar')), 'An exact language never returns the unlocalized record.');
        self::assertSame(['ar'], $languages(ContentLanguageFilter::exactLanguage('AR')), 'Exact matching uses the collation.');
        self::assertSame([], $languages(ContentLanguageFilter::exactLanguage('de')), 'No fallback when the language is missing.');
        self::assertSame([null], $languages(ContentLanguageFilter::unlocalizedOnly()), 'Unlocalized never returns a localized record.');
        self::assertSame([null, 'ar', 'en', 'fr'], $languages(ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::INCLUDE_DELETED));
        self::assertSame(['fr'], $languages(ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::DELETED_ONLY));
        self::assertSame([null, 'ar'], $languages(ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::NON_DELETED, 2));
        self::assertSame([], $products->listForManagement(new ProductContentListCriteriaDTO(999999, ContentLanguageFilter::allLanguages()))->items());

        $this->assertThrows(ProductContentNotFoundException::class, fn() => $products->getByIdForManagement($ids['fr']));
        self::assertSame('fr', $products->getByIdForManagement($ids['fr'], ProductDeletedStateEnum::DELETED_ONLY)->languageCode);
        self::assertSame('fr', $products->getByIdForManagement($ids['fr'], ProductDeletedStateEnum::INCLUDE_DELETED)->languageCode);
        $this->assertThrows(ProductContentNotFoundException::class, fn() => $products->getByIdForManagement($ids['ar'], ProductDeletedStateEnum::DELETED_ONLY));

        $all = new ProductContentListCriteriaDTO($productId, ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::INCLUDE_DELETED, 1);
        $first = $products->paginateForManagement($all, new PageRequest(1, 3));
        self::assertSame([4, 4, 3, 2, true, 'language_code'], [$first->total, $first->filtered, $first->perPage, $first->totalPages, $first->hasNext, $first->sortBy], 'maxResults does not bound a page.');
        self::assertSame([null, 'ar', 'en'], array_map(static fn(ProductContentDTO $dto): ?string => $dto->languageCode, $first->data));
        $second = $products->paginateForManagement($all, new PageRequest(2, 3));
        self::assertSame(['fr'], array_map(static fn(ProductContentDTO $dto): ?string => $dto->languageCode, $second->data));
        $byId = $products->paginateForManagement($all, new PageRequest(1, 100, 'id', 'DESC'));
        self::assertSame([$ids['en'], $ids['ar'], $ids['fr'], $ids['null']], array_map(static fn(ProductContentDTO $dto): int => $dto->id, $byId->data));
        $exact = $products->paginateForManagement(new ProductContentListCriteriaDTO($productId, ContentLanguageFilter::exactLanguage('ar')), new PageRequest());
        self::assertSame([1, 1, 20], [$exact->total, $exact->filtered, $exact->perPage], 'The counts use the same filter as the data.');
        $capped = $products->paginateForManagement($all, new PageRequest(1, 1000));
        self::assertSame(100, $capped->perPage);
    }

    public function testConsumerReadsAreExactAndHideRecordsOfInvisibleOwners(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('P'))->productId;
        $products = $this->productContentManagementService();
        $consumerProducts = $this->productContentConsumerService();
        $unlocalized = $products->create(new CreateProductContentCommand($productId, null, 'Shirt'))->contentId;
        $arabic = $products->create(new CreateProductContentCommand($productId, 'ar', 'قميص'))->contentId;
        $visible = fn(ExactContentLanguage $language): array => array_map(
            static fn(ProductContentDTO $dto): int => $dto->id,
            $consumerProducts->listVisible($productId, $language)->items(),
        );

        self::assertSame([], $visible(ExactContentLanguage::exactLanguage('ar')), 'An inactive Product hides its Content.');
        $this->activateProduct($productId);
        self::assertSame([$arabic], $visible(ExactContentLanguage::exactLanguage('ar')));
        self::assertSame([$arabic], $visible(ExactContentLanguage::exactLanguage('AR')));
        self::assertSame([$unlocalized], $visible(ExactContentLanguage::unlocalizedOnly()), 'Unlocalized never returns the Arabic record.');
        self::assertSame([], $visible(ExactContentLanguage::exactLanguage('en')), 'No fallback to the unlocalized record.');
        $products->softDelete($arabic);
        self::assertSame([], $visible(ExactContentLanguage::exactLanguage('ar')), 'A deleted record is hidden.');

        [$color, $red] = $this->configurableProduct($productId);
        $size = $this->optionManagementService()->create(new CreateOptionCommand($productId, 'size'))->optionId;
        $this->optionContentManagementService()->create(new CreateOptionContentCommand($size, null, 'Size'));
        $optionLabels = fn(int $optionId): int => count($this->optionContentConsumerService()->listVisible($optionId, ExactContentLanguage::unlocalizedOnly()));
        $valueLabels = fn(int $valueId): int => count($this->optionValueContentConsumerService()->listVisible($valueId, ExactContentLanguage::unlocalizedOnly()));
        self::assertSame(1, $optionLabels($color));
        self::assertSame(1, $valueLabels($red));
        self::assertSame(0, $optionLabels($size), 'An inactive Option hides its labels.');
        $green = $this->optionValueManagementService()->create(new CreateOptionValueCommand($color, 'green'))->optionValueId;
        $this->optionValueContentManagementService()->create(new CreateOptionValueContentCommand($green, null, 'Green'));
        self::assertSame(0, $valueLabels($green), 'An inactive Value hides its labels.');

        $this->productManagementService()->updateStatus($productId, ProductStatusEnum::INACTIVE);
        self::assertSame(0, $optionLabels($color), 'An inactive Product hides its Option labels.');
        self::assertSame(0, $valueLabels($red));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $consumerProducts->listVisible(0, ExactContentLanguage::unlocalizedOnly()));
    }

    /**
     * Makes the Product configurable with one labelled Option of two labelled Values, through the
     * public Content commands and reconfiguration.
     *
     * @return array{int, int, int} The Option and its two Values.
     */
    private function configurableProduct(int $productId): array
    {
        $color = $this->optionManagementService()->create(new CreateOptionCommand($productId, 'color'))->optionId;
        $red = $this->optionValueManagementService()->create(new CreateOptionValueCommand($color, 'red'))->optionValueId;
        $blue = $this->optionValueManagementService()->create(new CreateOptionValueCommand($color, 'blue'))->optionValueId;
        $this->optionContentManagementService()->create(new CreateOptionContentCommand($color, null, 'Color'));
        $this->optionValueContentManagementService()->create(new CreateOptionValueContentCommand($red, null, 'Red'));
        $this->optionValueContentManagementService()->create(new CreateOptionValueContentCommand($blue, null, 'Blue'));
        $this->productManagementService()->reconfigure(self::reconfiguration($productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));

        return [$color, $red, $blue];
    }

    private function labelIdOf(string $table, string $ownerColumn, int $ownerId): int
    {
        return (int) self::scalar('SELECT id FROM `' . $table . '` WHERE `' . $ownerColumn . '` = :o AND deleted_at IS NULL ORDER BY id LIMIT 1', ['o' => $ownerId]);
    }
}
