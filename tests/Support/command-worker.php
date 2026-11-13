<?php

/**
 * Child process for the concurrency tests: opens its own connection, builds the public services
 * through the factory, reports `ready` on stdout, and waits for one line on stdin. The parent
 * releases every worker of a round at once only after all of them are ready, so no worker races
 * against another's start-up and no timing is assumed. It then runs one public command and prints
 * the outcome.
 *
 * Usage: php command-worker.php <operation> <json-arguments>
 *   reconfigure          {"productId": int, "options": {optionId: [valueId, ...]}, "variants": [[[optionId, valueId], ...], reuseVariantId|null]}
 *   set-default          {"assignmentId": int}
 *   restore-as-default   {"assignmentId": int}
 *   create-assignment    {"productId": int, "mediaAssetId": int, "roleId": int|null}
 *   move-assignment      {"assignmentId": int, "position": int}
 *   product-status       {"productId": int, "status": "active"|"inactive"}
 *   variant-status       {"variantId": int, "status": "active"|"inactive"}
 *   variant-sku          {"variantId": int, "sku": string}
 *   move-option          {"optionId": int, "position": int}
 *   delete-product-content  {"contentId": int}
 *
 * Outcomes: `ok:<detail>`; `conflict` (composition or default conflict); `fc-inv`
 * (ProductBusinessRuleException); `sku-conflict` (VariantSkuAlreadyExistsException); otherwise
 * `error:<class>:<driver code>:<message>`.
 */

declare(strict_types=1);

use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductConfigurationRevisionConflictException;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
$operation = $argv[1] ?? '';
/** @var array<string, mixed> $arguments */
$arguments = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? $value : '';
};
$int = static function (string $name) use ($arguments): int {
    $value = $arguments[$name] ?? null;
    if (!is_int($value)) {
        throw new InvalidArgumentException('Worker argument ' . $name . ' must be an integer.');
    }

    return $value;
};
$string = static function (string $name) use ($arguments): string {
    $value = $arguments[$name] ?? null;
    if (!is_string($value)) {
        throw new InvalidArgumentException('Worker argument ' . $name . ' must be a string.');
    }

    return $value;
};

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('PRODUCT_TEST_DB_HOST'), $env('PRODUCT_TEST_DB_PORT'), $env('PRODUCT_TEST_DB_NAME')),
    $env('PRODUCT_TEST_DB_USER'),
    $env('PRODUCT_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$variants = ProductFactory::createVariantManagementService($pdo, $clock);
$options = ProductFactory::createOptionManagementService($pdo, $clock);
$assignments = ProductFactory::createImageAssignmentManagementService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) === false) {
    fwrite(STDERR, 'The release signal never arrived.');
    exit(1);
}

try {
    switch ($operation) {
        case 'reconfigure':
            $optionTargets = [];
            /** @var array<int|string, list<int>> $optionSpecs */
            $optionSpecs = $arguments['options'] ?? [];
            foreach ($optionSpecs as $optionId => $valueIds) {
                $optionTargets[] = new ReconfigureOptionTarget((int) $optionId, $valueIds);
            }
            $variantTargets = [];
            /** @var list<array{0: list<array{int, int}>, 1?: int|null}> $variantSpecs */
            $variantSpecs = $arguments['variants'] ?? [];
            foreach ($variantSpecs as $spec) {
                $variantTargets[] = new ReconfigureVariantTarget(
                    array_map(static fn(array $pair): OptionValueSelection => new OptionValueSelection($pair[0], $pair[1]), $spec[0]),
                    $spec[1] ?? null,
                );
            }
            $result = $products->reconfigure(new ReconfigureProductCommand($int('productId'), $int('revision'), $optionTargets, $variantTargets));
            echo 'ok:' . implode(',', $result->createdVariantIds);
            break;
        case 'set-default':
            $assignments->setDefault($int('assignmentId'));
            echo 'ok:' . $int('assignmentId');
            break;
        case 'restore-as-default':
            $assignments->restoreAsDefault($int('assignmentId'));
            echo 'ok:' . $int('assignmentId');
            break;
        case 'create-assignment':
            $roleId = $arguments['roleId'] ?? null;
            $created = $assignments->create(new CreateImageAssignmentCommand($int('productId'), null, $int('mediaAssetId'), is_int($roleId) ? $roleId : null));
            echo 'ok:' . $created->assignmentId;
            break;
        case 'move-assignment':
            $assignments->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($int('assignmentId'), $int('position')));
            echo 'ok:' . $int('assignmentId');
            break;
        case 'product-status':
            $products->updateStatus($int('productId'), ProductStatusEnum::from($string('status')));
            echo 'ok:' . $int('productId');
            break;
        case 'variant-status':
            $variants->updateStatus($int('variantId'), VariantStatusEnum::from($string('status')));
            echo 'ok:' . $int('variantId');
            break;
        case 'variant-sku':
            $variants->updateIdentifiers(new UpdateVariantIdentifiersCommand($int('variantId'), VariantIdentifierChange::set($string('sku')), VariantIdentifierChange::unchanged()));
            echo 'ok:' . $int('variantId');
            break;
        case 'move-option':
            $options->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($int('optionId'), $int('position')));
            echo 'ok:' . $int('optionId');
            break;
        case 'delete-product-content':
            $productContent->softDelete($int('contentId'));
            echo 'ok:' . $int('contentId');
            break;
        default:
            echo 'error:unknown operation';
            break;
    }
} catch (ProductConfigurationRevisionConflictException) {
    echo 'stale-revision';
} catch (VariantCompositionConflictException|ImageAssignmentDefaultConflictException) {
    echo 'conflict';
} catch (ProductBusinessRuleException) {
    echo 'fc-inv';
} catch (VariantSkuAlreadyExistsException) {
    echo 'sku-conflict';
} catch (Throwable $e) {
    $driverCode = $e instanceof PDOException && is_int($e->errorInfo[1] ?? null) ? (string) $e->errorInfo[1] : '';
    echo 'error:' . $e::class . ':' . $driverCode . ':' . $e->getMessage();
}
