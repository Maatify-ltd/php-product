<?php

/**
 * Consumer Verification Harness for maatify/php-product.
 *
 * Runs in an external Composer root that installed the package as a dependency. It
 * loads the package only through the production PSR-4 autoloader, uses only the
 * documented public API (the factory, the service interfaces, the commands, the public DTOs
 * and exceptions) and the schema asset delivered with the installed package, and drives a
 * real MySQL service. It loads nothing from the package repository itself: no source file,
 * no test class, no development autoloader.
 * Product names and Option and Value labels are written through the public Content commands.
 * One race runs from separate processes of this same script, on the same consumer autoloader,
 * released together by a barrier on stdin.
 */

declare(strict_types=1);

use Composer\InstalledVersions;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Consumer\Service\ProductConsumerServiceInterface;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\Product\Product\Management\Service\ProductManagementServiceInterface;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require __DIR__ . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "ok: {$message}\n";
}

function env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("FAIL: missing environment variable {$name}");
    }

    return $value;
}

/**
 * Race worker: this same script, started again by the harness with `race-worker <database> <code>`,
 * on the same consumer autoloader. It opens its own connection, builds the public service, reports
 * `ready`, waits for one line on stdin (the barrier), creates one Product, and prints the outcome.
 */
if (($argv[1] ?? '') === 'race-worker') {
    $workerPdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('PRODUCT_TEST_DB_HOST'), env('PRODUCT_TEST_DB_PORT'), $argv[2] ?? ''),
        env('PRODUCT_TEST_DB_USER'),
        env('PRODUCT_TEST_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $workerService = ProductFactory::createProductManagementService($workerPdo, new SystemClock(new DateTimeZone('UTC')));
    fwrite(STDOUT, "ready\n");
    fflush(STDOUT);
    if (fgets(STDIN) === false) {
        fwrite(STDERR, "The release signal never arrived.\n");
        exit(1);
    }
    try {
        echo 'created:' . $workerService->create(new CreateProductCommand($argv[3] ?? ''))->productId;
    } catch (ProductCodeAlreadyExistsException) {
        echo 'conflict';
    } catch (Throwable $e) {
        echo 'error:' . $e::class . ':' . $e->getMessage();
    }
    exit(0);
}

/**
 * Starts one race worker per entry, releases them together once every one is ready, and returns
 * their printed outcomes.
 *
 * @param list<string> $codes
 *
 * @return list<string>
 */
function raceCreates(string $database, array $codes): array
{
    $workers = [];
    foreach ($codes as $code) {
        $process = proc_open([PHP_BINARY, __FILE__, 'race-worker', $database, $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'a race worker process starts');
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    foreach ($workers as $worker) {
        $line = fgets($worker['pipes'][1]);
        if ($line !== "ready\n") {
            throw new RuntimeException('FAIL: a race worker did not become ready: ' . stream_get_contents($worker['pipes'][2]));
        }
    }
    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fclose($worker['pipes'][0]);
    }
    $outcomes = [];
    foreach ($workers as $worker) {
        $outcome = (string) stream_get_contents($worker['pipes'][1]);
        $errors = (string) stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        check(proc_close($worker['process']) === 0, 'a race worker exits cleanly' . ($errors === '' ? '' : ': ' . $errors));
        $outcomes[] = $outcome;
    }

    return $outcomes;
}

check(InstalledVersions::isInstalled('maatify/php-product'), 'package is installed through Composer');
check(InstalledVersions::isInstalled('maatify/php-persistence'), 'successor Persistence is installed');
check(InstalledVersions::getPrettyVersion('maatify/php-persistence') === 'v1.0.0-rc.1', 'Persistence resolves to the exact trial RC');
check(InstalledVersions::getReference('maatify/php-persistence') === '34c3b874525d36ae064c162cdd553bc75d18772b', 'Persistence matches the verified published revision');
check(!InstalledVersions::isInstalled('maatify/persistence'), 'legacy Persistence is absent');
$installPath = InstalledVersions::getInstallPath('maatify/php-product');
check(is_string($installPath) && is_dir($installPath), 'installed package path exists');
check(!is_link((string) $installPath), 'package was copied, not symlinked from the repository');

// Production PSR-4 autoload: the public classes resolve from the installed package.
$installRoot = (string) realpath((string) $installPath);
foreach ([ProductFactory::class, CreateProductCommand::class, ProductManagementServiceInterface::class, ProductConsumerServiceInterface::class, ProductStatusEnum::class] as $class) {
    $file = (new ReflectionClass($class))->getFileName();
    check(
        is_string($file) && str_starts_with((string) realpath($file), $installRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR),
        "{$class} is autoloaded from the installed package",
    );
}
check(!class_exists('Maatify\\Product\\Tests\\Integration\\DatabaseTestCase'), 'test namespaces are not autoloadable by the consumer');

$schemaFile = $installPath . '/schema/product.sql';
check(is_file($schemaFile), 'schema/product.sql is delivered with the installed package');
$sql = (string) file_get_contents($schemaFile);
$statements = [];
foreach (explode(";\n", (string) preg_replace('/^--.*$/m', '', $sql)) as $statement) {
    if (trim($statement) !== '') {
        $statements[] = trim($statement);
    }
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$base = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', env('PRODUCT_TEST_DB_HOST'), env('PRODUCT_TEST_DB_PORT'));
$admin = new PDO($base, env('PRODUCT_TEST_DB_USER'), env('PRODUCT_TEST_DB_PASSWORD'), $pdoOptions);
$database = 'product_consumer_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `{$database}`");

try {
    $pdo = new PDO($base . ';dbname=' . $database, env('PRODUCT_TEST_DB_USER'), env('PRODUCT_TEST_DB_PASSWORD'), $pdoOptions);
    $version = $pdo->query('SELECT VERSION()');
    check($version !== false && str_starts_with((string) $version->fetchColumn(), env('PRODUCT_TEST_EXPECTED_VERSION')), 'connected to the expected MySQL version');

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    $tables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'maa\\_product\\_%'");
    check($tables !== false && (int) $tables->fetchColumn() === 10, 'the installed schema creates the ten Product tables');

    // Realistic workflow: Host input -> public API -> service -> database -> observable result.
    $service = ProductFactory::createProductManagementService($pdo, new SystemClock(new DateTimeZone('UTC')));
    $clock = new SystemClock(new DateTimeZone('UTC'));
    $shop = ProductFactory::forHost($pdo, $clock);
    $consumer = $shop->consumerProducts();

    $created = $service->create(new CreateProductCommand('SIMPLE-1'));
    check($created->productId > 0 && $created->variantId > 0, 'creating a Product returns its Product and Variant identities');

    $product = $service->getByIdForManagement($created->productId);
    check($product->code === 'SIMPLE-1', 'the stored Product is readable through the public API');
    check($product->status === ProductStatusEnum::INACTIVE, 'a new Product is inactive');
    check($product->deletedAt === null, 'a new Product is not soft deleted');

    $page = $service->paginateForManagement(new ProductListCriteriaDTO(search: 'SIMPLE-1'), new PageRequest(1, 1));
    check($page instanceof PageResultDTO && $page->data[0]->id === $created->productId, 'public management pagination uses the successor types');
    $serializedPage = $page->jsonSerialize();
    check(
        $serializedPage['pagination']['page'] === 1 && $serializedPage['pagination']['per_page'] === 1
        && $serializedPage['pagination']['filtered'] === 1 && $serializedPage['pagination']['has_next'] === false,
        'pagination preserves the consumer response shape',
    );

    $variants = $pdo->prepare('SELECT status, display_order FROM maa_product_variants WHERE product_id = ?');
    $variants->execute([$created->productId]);
    $rows = $variants->fetchAll(PDO::FETCH_NUM);
    check($rows === [['active', 1]], 'the Product has exactly one active empty-composition Variant');

    // A real race from separate consumer processes: one winner, every other writer a typed conflict.
    $outcomes = raceCreates($database, ['RACE-1', 'race-1', 'RACE-1', 'Race-1']);
    $winners = array_values(array_filter($outcomes, static fn(string $outcome): bool => str_starts_with($outcome, 'created:')));
    $conflicts = array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'conflict');
    check(count($winners) === 1 && count($conflicts) === 3, 'concurrent creates of one code yield one Product and typed conflicts (' . implode(' | ', $outcomes) . ')');
    $raced = $pdo->query("SELECT COUNT(*) FROM maa_product_products WHERE code = 'RACE-1'");
    check($raced !== false && (int) $raced->fetchColumn() === 1, 'the race left exactly one Product with that code');

    $second = $service->create(new CreateProductCommand('SIMPLE-2'));
    check($second->variantId !== $created->variantId, 'each Product receives its own Variant identity');

    try {
        $service->create(new CreateProductCommand('simple-1'));
        check(false, 'a duplicate code must conflict');
    } catch (ProductCodeAlreadyExistsException) {
        check(true, 'a duplicate code conflicts case-insensitively');
    }
    $count = $pdo->query('SELECT COUNT(*) FROM maa_product_products');
    check($count !== false && (int) $count->fetchColumn() === 3, 'the conflicting create left no extra Product behind');

    try {
        $consumer->getById($created->productId);
        check(false, 'an inactive Product must not be consumer-visible');
    } catch (ProductNotFoundException) {
        check(true, 'an inactive Product is hidden from the consumer read');
    }

    try {
        $consumer->getById(999999);
        check(false, 'an unknown Product must not be found');
    } catch (ProductNotFoundException) {
        check(true, 'an unknown Product raises the not-found exception');
    }

    // Lifecycle, reconfiguration, resolution, and image assignments through the public API.
    $options = ProductFactory::createOptionManagementService($pdo, $clock);
    $values = ProductFactory::createOptionValueManagementService($pdo, $clock);
    $variants = ProductFactory::createVariantManagementService($pdo, $clock);
    $consumerVariants = $shop->consumerVariants();
    $productId = $created->productId;
    $productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
    $consumerProductContent = $shop->consumerProductContent();
    $optionContent = ProductFactory::createOptionContentManagementService($pdo, $clock);
    $valueContent = ProductFactory::createOptionValueContentManagementService($pdo, $clock);
    $nameId = $productContent->create(new CreateProductContentCommand($productId, null, 'Simple one'))->contentId;
    check($consumerProductContent->listVisible($productId, ExactContentLanguage::unlocalizedOnly())->isEmpty(), 'Content of an inactive Product is not consumer-visible');
    $service->updateStatus($productId, ProductStatusEnum::ACTIVE);
    check($consumer->getById($productId)->status === ProductStatusEnum::ACTIVE, 'a named Product with a valid configuration can be activated');
    check(count($consumerProductContent->listVisible($productId, ExactContentLanguage::unlocalizedOnly())) === 1, 'the unlocalized name of an active Product is consumer-visible');
    check($consumerProductContent->listVisible($productId, ExactContentLanguage::exactLanguage('en'))->isEmpty(), 'an exact-language read never falls back to the unlocalized name');
    try {
        $productContent->softDelete($nameId);
        check(false, 'the last name of an active Product cannot be soft deleted');
    } catch (ProductBusinessRuleException) {
        check(true, 'the last name of an active Product cannot be soft deleted');
    }
    try {
        $productContent->create(new CreateProductContentCommand($productId, null, 'Again'));
        check(false, 'the unlocalized Content identity is unique');
    } catch (ProductContentAlreadyExistsException) {
        check(true, 'the unlocalized Content identity is unique');
    }
    check($consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO())->id === $created->variantId, 'an empty selection resolves the simple Variant');

    $color = $options->create(new CreateOptionCommand($productId, 'color'))->optionId;
    $red = $values->create(new CreateOptionValueCommand($color, 'red'))->optionValueId;
    $blue = $values->create(new CreateOptionValueCommand($color, 'blue'))->optionValueId;
    $optionContent->create(new CreateOptionContentCommand($color, null, 'Color'));
    $valueContent->create(new CreateOptionValueContentCommand($red, null, 'Red'));
    $valueContent->create(new CreateOptionValueContentCommand($blue, null, 'Blue'));
    check($options->getByIdForManagement($color)->status->value === 'inactive', 'a new Option is inactive');

    $result = $service->reconfigure(new ReconfigureProductCommand($productId, $service->getConfigurationRevision($productId), [new ReconfigureOptionTarget($color, [$red, $blue])], [
        new ReconfigureVariantTarget([new OptionValueSelection($color, $red)]),
        new ReconfigureVariantTarget([new OptionValueSelection($color, $blue)]),
    ]));
    check(count($result->createdVariantIds) === 2 && $result->deactivatedVariantIds === [$created->variantId], 'reconfiguration creates the new Variants and deactivates the empty one');
    [$redVariant, $blueVariant] = $result->createdVariantIds;
    $variantPage = $consumerVariants->paginateByProductId($productId, new PageRequest(2, 1));
    check($variantPage->total === 2 && ($variantPage->data[0]->id ?? null) === $blueVariant, 'consumer Variant pagination continues in visible order');
    $optionPage = $shop->consumerOptions()->paginateVisibleByProductId($productId, new PageRequest(1, 1));
    check($optionPage->total === 1 && ($optionPage->data[0]->id ?? null) === $color, 'consumer Option pagination returns the visible Option');
    $valuePage = $shop->consumerOptionValues()->paginateVisibleByOptionId($color, new PageRequest(2, 1));
    check($valuePage->total === 2 && ($valuePage->data[0]->id ?? null) === $blue, 'consumer Option Value pagination continues in visible order');
    check($consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO([new OptionValueSelection($color, $blue)]))->id === $blueVariant, 'a complete selection resolves to its Variant');
    try {
        $consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO());
        check(false, 'an incomplete selection must be rejected');
    } catch (ProductInvalidArgumentException) {
        check(true, 'an incomplete selection is a validation failure');
    }
    try {
        $service->reconfigure(new ReconfigureProductCommand($productId, $service->getConfigurationRevision($productId), [new ReconfigureOptionTarget($color, [$red])], [new ReconfigureVariantTarget([new OptionValueSelection($color, $red)])]));
        check(false, 'an existing composition without reuseVariantId must conflict');
    } catch (VariantCompositionConflictException) {
        check(true, 'an existing composition is reused only by explicit identity');
    }
    try {
        $variants->softDelete($blueVariant);
        check(false, 'deleting the Variant that represents blue must be rejected');
    } catch (ProductBusinessRuleException) {
        check(true, 'a mutation that would break the configuration is a business-rule failure');
    }
    $back = $service->reconfigure(new ReconfigureProductCommand($productId, $service->getConfigurationRevision($productId), [], [new ReconfigureVariantTarget([], $created->variantId)]));
    check($back->reactivatedVariantIds === [$created->variantId] && $back->deactivatedVariantIds === [$redVariant, $blueVariant], 'the transition back to simple reuses the original empty Variant');
    try {
        $consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO([new OptionValueSelection($color, $red)]));
        check(false, 'a retired Option must not be selectable');
    } catch (ProductInvalidArgumentException) {
        check(true, 'a retired Option is not selectable');
    }

    $roles = ProductFactory::createImageRoleManagementService($pdo, $clock);
    $assignments = ProductFactory::createImageAssignmentManagementService($pdo, $clock);
    $hero = $roles->create(new CreateImageRoleCommand('hero'))->roleId;
    $first = $assignments->create(new CreateImageAssignmentCommand($productId, null, 501, $hero))->assignmentId;
    $second = $assignments->create(new CreateImageAssignmentCommand($productId, null, '502', $hero))->assignmentId;
    $assignments->setDefault($first);
    $assignments->setDefault($second);
    check(!$assignments->getByIdForManagement($first)->isDefault && $assignments->getByIdForManagement($second)->isDefault, 'set-default replaces the previous default of the scope');
    $assignments->softDelete($second);
    $imagePage = $shop->consumerImageAssignments()->paginateVisibleByExactScope(
        new ImageAssignmentExactScopeCriteriaDTO($productId, null, $hero, null, null),
        new PageRequest(1, 1),
    );
    check($imagePage->total === 1 && ($imagePage->data[0]->id ?? null) === $first, 'consumer image pagination excludes deleted assignments in the exact scope');
    check(!$assignments->getByIdForManagement($first)->isDefault, 'deleting the default promotes nothing');

    // The second session commits a new Option after the Host has established its read view.
    $writerPdo = new PDO($base . ';dbname=' . $database, env('PRODUCT_TEST_DB_USER'), env('PRODUCT_TEST_DB_PASSWORD'), $pdoOptions);
    $otherOptions = ProductFactory::createOptionManagementService($writerPdo, $clock);
    $lookupOpens = 0;
    $host = ProductFactory::forHost($pdo, $clock, static function () use ($base, $database, $pdoOptions, &$lookupOpens): PDO {
        ++$lookupOpens;

        return new PDO($base . ';dbname=' . $database, env('PRODUCT_TEST_DB_USER'), env('PRODUCT_TEST_DB_PASSWORD'), $pdoOptions);
    });
    check($host->consumerProducts()->getByCode('simple-1')->id === $productId && $lookupOpens === 0, 'Consumer reads on a configured Host factory do not open the owner lookup connection');
    $hostOptions = $host->options();
    $hostValues = $host->optionValues();
    check($lookupOpens === 0, 'configuring the Host and building services does not open the lookup connection');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $service->getByIdForManagement($productId);
        $lateOption = $otherOptions->create(new CreateOptionCommand($productId, 'late-host-option'))->optionId;
        $hostOption = $hostOptions->create(new CreateOptionCommand($productId, 'local-host-option'))->optionId;
        $hostOptions->softDelete($hostOption);
        check($lookupOpens === 0, 'a Host-local identity does not open the lookup connection');
        try {
            $hostOptions->getByIdForManagement($lateOption);
            check(false, 'the Host snapshot must hide the newly committed Option');
        } catch (OptionNotFoundException) {
            check(true, 'the Host snapshot hides the newly committed Option');
        }

        $hostValues->create(new CreateOptionValueCommand($lateOption, 'host-value'));
        check($lookupOpens === 1, 'the first owner lookup miss opens one connection');
        $hostOptions->softDelete($lateOption);
        check($lookupOpens === 1, 'a second service reuses the lookup connection');
        check($pdo->inTransaction(), 'the Host still owns the transaction after the mutation');
        $deleted = $hostOptions->getByIdForManagement($lateOption, ProductDeletedStateEnum::DELETED_ONLY);
        check($deleted->deletedAt !== null, 'owner lookup lets the Host soft-delete the newly committed Option');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    check($otherOptions->getByIdForManagement($lateOption)->deletedAt === null, 'Host rollback leaves the committed Option live');

    $service->softDelete($productId);
    try {
        $service->updateStatus($productId, ProductStatusEnum::INACTIVE);
        check(false, 'a soft-deleted Product must accept nothing but restore');
    } catch (ProductBusinessRuleException) {
        check(true, 'a soft-deleted Product accepts nothing but restore');
    }
    $service->restore($productId);
    check($consumer->getById($productId)->status === ProductStatusEnum::ACTIVE, 'restore preserves the saved status');
    check($consumer->getByCode('simple-1')->id === $productId, 'consumer lookup by code returns the visible Product');
    $variantManagement = ProductFactory::createVariantManagementService($pdo, $clock);
    $variantManagement->updateIdentifiers(new UpdateVariantIdentifiersCommand(
        $created->variantId,
        VariantIdentifierChange::set('SIMPLE-SKU'),
        VariantIdentifierChange::set('000501'),
    ));
    check($variantManagement->getBySkuForManagement('simple-sku')->id === $created->variantId, 'management lookup finds a Variant by SKU');
    check($variantManagement->getByBarcodeForManagement('000501')->id === $created->variantId, 'management lookup keeps barcode leading zeroes');
    try {
        $consumerVariants->getById($redVariant);
        check(false, 'an inactive Variant must not be consumer-visible');
    } catch (VariantNotFoundException) {
        check(true, 'an inactive Variant is hidden from consumers');
    }
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo "Consumer verification passed.\n";
