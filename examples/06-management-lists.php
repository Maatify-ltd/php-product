<?php

/**
 * Example 6: management lists, search, pagination, and business-key reads.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    three Products sharing one code prefix (one active, one inactive, one soft deleted) and
 *              three Options of one of them.
 *  - Calls:    ProductManagementServiceInterface::listForManagement(), paginateForManagement(),
 *              getByCodeForManagement(), updateStatus(), softDelete(),
 *              ProductContentManagementServiceInterface::create(), listForManagement(), paginateForManagement(),
 *              OptionManagementServiceInterface::listByProductIdForManagement(), paginateByProductIdForManagement(),
 *              OptionValueManagementServiceInterface::create(), listByOptionIdForManagement(),
 *              paginateByOptionIdForManagement().
 *  - Result:   ProductCollectionDTO, ProductContentCollectionDTO, and OptionCollectionDTO lists bounded by
 *              `maxResults`, and maatify/php-persistence PageResultDTO pages of ProductDTO, ProductContentDTO, and
 *              OptionDTO with their metadata.
 *  - Boundary: lists filter by status (`null` means any) and deleted state (non-deleted by default) and
 *              are truncated at `maxResults` without a "more" flag; pages hold 1 to 100 rows with a sort
 *              whitelist; only Products can be searched (a literal substring of `code`), and `total`
 *              counts without the search while `filtered` counts with it. Content lists select by owner
 *              and by one language state: all languages, the unlocalized record only, or one exact
 *              language, with no fallback.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\DTO\ProductContentDTO;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Criteria\ProductContentListCriteriaDTO;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\OptionValue\DTO\OptionValueDTO;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$autoload = getenv('PRODUCT_EXAMPLE_AUTOLOAD');
require is_string($autoload) && $autoload !== '' ? $autoload : __DIR__ . '/../vendor/autoload.php';

$setting = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Set {$name} (see the header of example 01).\n");
        exit(2);
    }

    return $value;
};

$expect = static function (bool $condition, string $claim): void {
    if (!$condition) {
        throw new RuntimeException('Unexpected result: ' . $claim);
    }
};

$pdo = new PDO(
    $setting('PRODUCT_EXAMPLE_DSN'),
    $setting('PRODUCT_EXAMPLE_USER'),
    $setting('PRODUCT_EXAMPLE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
$options = ProductFactory::createOptionManagementService($pdo, $clock);

// Catalog setup: one prefix shared by three Products, so the search isolates them in a shared database.
$prefix = 'LIST-' . bin2hex(random_bytes(4)) . '-';
$alpha = $products->create(new CreateProductCommand($prefix . 'ALPHA'))->productId;
$beta = $products->create(new CreateProductCommand($prefix . 'BETA'))->productId;
$gamma = $products->create(new CreateProductCommand($prefix . 'GAMMA'))->productId;
$productContent->create(new CreateProductContentCommand($alpha, null, 'Alpha'));
$products->updateStatus($alpha, ProductStatusEnum::ACTIVE);
$products->softDelete($gamma);

$codes = static fn(ProductDTO ...$items): string => implode(', ', array_map(static fn(ProductDTO $item): string => $item->code, $items));

// Unpaginated lists: status filter, deleted-state filter, and the `maxResults` bound (ordered by id).
$active = $products->listForManagement(new ProductListCriteriaDTO(status: ProductStatusEnum::ACTIVE, search: $prefix));
$expect($codes(...$active->items()) === $prefix . 'ALPHA', 'the active filter returns only ALPHA');
echo 'active: ' . $codes(...$active->items()) . "\n";

$anyStatus = $products->listForManagement(new ProductListCriteriaDTO(search: $prefix));
$expect($codes(...$anyStatus->items()) === $prefix . 'ALPHA, ' . $prefix . 'BETA', 'the default deleted state hides GAMMA');
echo 'non-deleted, any status: ' . $codes(...$anyStatus->items()) . "\n";

$deleted = $products->listForManagement(new ProductListCriteriaDTO(deletedState: ProductDeletedStateEnum::DELETED_ONLY, search: $prefix));
$expect($codes(...$deleted->items()) === $prefix . 'GAMMA', 'DELETED_ONLY returns only GAMMA');
echo 'deleted only: ' . $codes(...$deleted->items()) . "\n";

$bounded = $products->listForManagement(new ProductListCriteriaDTO(deletedState: ProductDeletedStateEnum::INCLUDE_DELETED, maxResults: 2, search: $prefix));
$expect(count($bounded) === 2 && $codes(...$bounded->items()) === $prefix . 'ALPHA, ' . $prefix . 'BETA', 'maxResults truncates to the first two by id');
echo 'include deleted, maxResults 2: ' . $codes(...$bounded->items()) . "\n";

// Pagination: a PageRequest with a whitelisted sort key; `filtered` honours the search, `total` does not.
$page = $products->paginateForManagement(
    new ProductListCriteriaDTO(deletedState: ProductDeletedStateEnum::INCLUDE_DELETED, search: $prefix),
    new PageRequest(1, 2, 'code', 'DESC'),
);
$expect($codes(...$page->data) === $prefix . 'GAMMA, ' . $prefix . 'BETA', 'page 1 sorted by code DESC');
$expect($page->filtered === 3 && $page->total >= 3 && $page->totalPages === 2 && $page->hasNext && !$page->hasPrevious, 'page 1 metadata');
$expect($page->sortBy === 'code' && $page->sortDirection === SortDirectionEnum::DESC, 'the applied sort is reported');
echo "page 1/{$page->totalPages} by code DESC: " . $codes(...$page->data) . " (filtered {$page->filtered})\n";

$last = $products->paginateForManagement(
    new ProductListCriteriaDTO(deletedState: ProductDeletedStateEnum::INCLUDE_DELETED, search: $prefix),
    new PageRequest(2, 2, 'code', 'DESC'),
);
$expect($codes(...$last->data) === $prefix . 'ALPHA' && !$last->hasNext && $last->hasPrevious, 'page 2 holds the rest');
echo "page 2/{$last->totalPages}: " . $codes(...$last->data) . "\n";

// An unknown sort key falls back to the list's default order (by id).
$fallback = $products->paginateForManagement(new ProductListCriteriaDTO(search: $prefix), new PageRequest(1, 20, 'no_such_key'));
$expect($codes(...$fallback->data) === $prefix . 'ALPHA, ' . $prefix . 'BETA', 'an unknown sort key falls back to id');
echo "unknown sort key: falls back to {$fallback->sortBy}\n";

// Business-key read: the code compares under the collation and honours the deleted-state filter.
$byCode = $products->getByCodeForManagement(strtolower($prefix . 'beta'));
$expect($byCode->id === $beta, 'getByCodeForManagement finds BETA case-insensitively');
echo "by code: {$byCode->code} is product {$byCode->id}\n";

try {
    $products->getByCodeForManagement($prefix . 'GAMMA');
    throw new RuntimeException('A soft-deleted Product must not be found by the default read.');
} catch (ProductNotFoundException) {
    $expect($products->getByCodeForManagement($prefix . 'GAMMA', ProductDeletedStateEnum::INCLUDE_DELETED)->id === $gamma, 'INCLUDE_DELETED finds GAMMA');
    echo "by code: GAMMA found only with INCLUDE_DELETED\n";
}

// Content of one owner: listed and paged by language, the unlocalized record first.
$productContent->create(new CreateProductContentCommand($alpha, 'en', 'Alpha (en)'));
$productContent->create(new CreateProductContentCommand($alpha, 'ar', 'Alpha (ar)'));
$languages = static fn(ProductContentDTO ...$items): string => implode(', ', array_map(static fn(ProductContentDTO $item): string => $item->languageCode ?? 'unlocalized', $items));
$allContent = $productContent->listForManagement(new ProductContentListCriteriaDTO($alpha, ContentLanguageFilter::allLanguages()));
$expect($languages(...$allContent->items()) === 'unlocalized, ar, en', 'all languages, the unlocalized record first');
echo 'content of ALPHA: ' . $languages(...$allContent->items()) . "\n";
$english = $productContent->listForManagement(new ProductContentListCriteriaDTO($alpha, ContentLanguageFilter::exactLanguage('EN')));
$expect($languages(...$english->items()) === 'en', 'an exact language compares under the collation');
$unlocalized = $productContent->listForManagement(new ProductContentListCriteriaDTO($alpha, ContentLanguageFilter::unlocalizedOnly()));
$expect($languages(...$unlocalized->items()) === 'unlocalized', 'the unlocalized record only');
$contentPage = $productContent->paginateForManagement(new ProductContentListCriteriaDTO($alpha, ContentLanguageFilter::allLanguages()), new PageRequest(1, 2));
$expect($languages(...$contentPage->data) === 'unlocalized, ar' && $contentPage->total === 3 && $contentPage->totalPages === 2, 'the Content page 1');
echo "content of ALPHA page 1/{$contentPage->totalPages}: " . $languages(...$contentPage->data) . "\n";

// An owner-scoped family: the Options of one Product, ordered by display_order then id.
foreach (['color', 'size', 'material'] as $optionCode) {
    $options->create(new CreateOptionCommand($beta, $optionCode));
}

$optionCodes = static fn(OptionDTO ...$items): string => implode(', ', array_map(static fn(OptionDTO $item): string => $item->code, $items));

$optionList = $options->listByProductIdForManagement($beta, new OptionListCriteriaDTO(maxResults: 2));
$expect($optionCodes(...$optionList->items()) === 'color, size', 'the Option list is bounded by maxResults');
echo 'options, maxResults 2: ' . $optionCodes(...$optionList->items()) . "\n";

$optionPage = $options->paginateByProductIdForManagement($beta, new OptionListCriteriaDTO(), new PageRequest(2, 2));
$expect($optionCodes(...$optionPage->data) === 'material' && $optionPage->total === 3 && $optionPage->filtered === 3, 'the Option page 2');
echo "options page 2/{$optionPage->totalPages}: " . $optionCodes(...$optionPage->data) . "\n";

$expect($options->listByProductIdForManagement($alpha)->isEmpty(), 'a Product without Options lists nothing');
echo "options of a Product without Options: none\n";

// Option Values are listed and paged within their Option, ordered by position; inactive Values included.
$values = ProductFactory::createOptionValueManagementService($pdo, $clock);
$color = $optionList->items()[0]->id;
foreach (['red', 'green', 'blue'] as $valueCode) {
    $values->create(new CreateOptionValueCommand($color, $valueCode));
}
$valueCodes = static fn(OptionValueDTO ...$items): string => implode(', ', array_map(static fn(OptionValueDTO $item): string => $item->code, $items));
$valueList = $values->listByOptionIdForManagement($color, new OptionValueListCriteriaDTO(status: OptionValueStatusEnum::INACTIVE));
$expect($valueCodes(...$valueList->items()) === 'red, green, blue', 'new Values are inactive and listed by position');
$valuePage = $values->paginateByOptionIdForManagement($color, new OptionValueListCriteriaDTO(), new PageRequest(1, 2));
$expect($valueCodes(...$valuePage->data) === 'red, green' && $valuePage->total === 3 && $valuePage->totalPages === 2, 'the Option Value page 1');
echo "values of color page 1/{$valuePage->totalPages}: " . $valueCodes(...$valuePage->data) . "\n";

echo "OK: example 06 completed\n";
