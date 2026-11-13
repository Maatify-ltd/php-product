<?php

declare(strict_types=1);

namespace Maatify\Product\Factory;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\Consumer\Service\OptionContentConsumerService;
use Maatify\Product\Content\Consumer\Service\OptionContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\OptionValueContentConsumerService;
use Maatify\Product\Content\Consumer\Service\OptionValueContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\ProductContentConsumerService;
use Maatify\Product\Content\Consumer\Service\ProductContentConsumerServiceInterface;
use Maatify\Product\Content\Enum\ContentOwnerEnum;
use Maatify\Product\Content\Management\Service\OptionContentManagementService;
use Maatify\Product\Content\Management\Service\OptionContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\OptionValueContentManagementService;
use Maatify\Product\Content\Management\Service\OptionValueContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\ProductContentManagementService;
use Maatify\Product\Content\Management\Service\ProductContentManagementServiceInterface;
use Maatify\Product\Content\Repository\Pdo\PdoContentCommandRepository;
use Maatify\Product\Content\Repository\Pdo\PdoContentQueryReader;
use Maatify\Product\Content\Service\ContentLifecycle;
use Maatify\Product\ImageAssignment\Consumer\Service\ImageAssignmentConsumerService;
use Maatify\Product\ImageAssignment\Consumer\Service\ImageAssignmentConsumerServiceInterface;
use Maatify\Product\ImageAssignment\Management\Service\ImageAssignmentManagementService;
use Maatify\Product\ImageAssignment\Management\Service\ImageAssignmentManagementServiceInterface;
use Maatify\Product\ImageAssignment\Repository\Pdo\PdoImageAssignmentCommandRepository;
use Maatify\Product\ImageAssignment\Repository\Pdo\PdoImageAssignmentQueryReader;
use Maatify\Product\ImageRole\Management\Service\ImageRoleManagementService;
use Maatify\Product\ImageRole\Management\Service\ImageRoleManagementServiceInterface;
use Maatify\Product\ImageRole\Repository\Pdo\PdoImageRoleCommandRepository;
use Maatify\Product\ImageRole\Repository\Pdo\PdoImageRoleQueryReader;
use Maatify\Product\Option\Consumer\Service\OptionConsumerService;
use Maatify\Product\Option\Consumer\Service\OptionConsumerServiceInterface;
use Maatify\Product\Option\Management\Service\OptionManagementService;
use Maatify\Product\Option\Management\Service\OptionManagementServiceInterface;
use Maatify\Product\Option\Repository\Pdo\PdoOptionCommandRepository;
use Maatify\Product\Option\Repository\Pdo\PdoOptionQueryReader;
use Maatify\Product\OptionValue\Consumer\Service\OptionValueConsumerService;
use Maatify\Product\OptionValue\Consumer\Service\OptionValueConsumerServiceInterface;
use Maatify\Product\OptionValue\Management\Service\OptionValueManagementService;
use Maatify\Product\OptionValue\Management\Service\OptionValueManagementServiceInterface;
use Maatify\Product\OptionValue\Repository\Pdo\PdoOptionValueCommandRepository;
use Maatify\Product\OptionValue\Repository\Pdo\PdoOptionValueQueryReader;
use Maatify\Product\Product\Consumer\Service\ProductConsumerService;
use Maatify\Product\Product\Consumer\Service\ProductConsumerServiceInterface;
use Maatify\Product\Product\Management\Service\ProductManagementService;
use Maatify\Product\Product\Management\Service\ProductManagementServiceInterface;
use Maatify\Product\Product\Repository\Pdo\PdoProductCommandRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductConfigurationRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductQueryReader;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerService;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerServiceInterface;
use Maatify\Product\Variant\Management\Service\VariantManagementService;
use Maatify\Product\Variant\Management\Service\VariantManagementServiceInterface;
use Maatify\Product\Variant\Repository\Pdo\PdoVariantCommandRepository;
use Maatify\Product\Variant\Repository\Pdo\PdoVariantQueryReader;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * Framework-neutral construction of the public Product services.
 *
 * Every capability has a Management service (creation, lifecycle, and management reads) and,
 * when it has a consumer read, a separate Consumer service that returns only consumer-visible
 * records and writes nothing.
 *
 * The Host supplies the PDO connection to a database where `schema/product.sql`
 * has been installed, and its Clock. The factory holds no container, state, or
 * global. Every service built on one connection shares its transactions: a
 * transaction the Host has already started on it is never committed or rolled back
 * by the package; each operation runs in its own savepoint inside it, and a failed
 * operation is rolled back to that savepoint, so none of its writes reach the Host's commit.
 *
 * Every builder takes the PDO connection, which must use
 * `PDO::ERRMODE_EXCEPTION` and carries all writes of one operation, and the Host Clock, whose
 * instant is stored as the record timestamp and whose timezone hydrates the returned timestamps.
 *
 * Supply an independent autocommit PDO on the same primary and schema to the Management builders
 * that accept `$ownerLookupPdo` when a Host transaction may have an older InnoDB read view.
 * It only resolves immutable owner identities missing from the transaction's own view.
 */
final class ProductFactory
{
    private function __construct() {}

    /**
     * Builds the Product Management service: creation, management reads, lifecycle, and reconfiguration.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createProductManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): ProductManagementServiceInterface
    {
        self::requireExceptionMode($pdo);
        $ordering = new ScopedOrderingManager();

        return new ProductManagementService(
            new PdoProductCommandRepository($pdo),
            new PdoVariantCommandRepository($pdo, $ordering),
            new PdoProductQueryReader($pdo, $clock),
            new PdoProductConfigurationRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Product Consumer service: the consumer-visible Product read.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createProductConsumerService(PDO $pdo, ClockInterface $clock): ProductConsumerServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new ProductConsumerService(new PdoProductQueryReader($pdo, $clock));
    }

    /**
     * Builds the Variant Management service: management reads and lifecycle.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createVariantManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): VariantManagementServiceInterface
    {
        self::requireExceptionMode($pdo);
        $ordering = new ScopedOrderingManager();

        return new VariantManagementService(
            new PdoVariantQueryReader($pdo, $clock),
            new PdoVariantCommandRepository($pdo, $ordering),
            new PdoProductConfigurationRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Variant Consumer service: consumer-visible reads and composition resolution.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createVariantConsumerService(PDO $pdo, ClockInterface $clock): VariantConsumerServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new VariantConsumerService(new PdoVariantQueryReader($pdo, $clock));
    }

    /**
     * Builds the Option Management service: creation, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): OptionManagementServiceInterface
    {
        self::requireExceptionMode($pdo);
        $ordering = new ScopedOrderingManager();

        return new OptionManagementService(
            new PdoOptionCommandRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoOptionQueryReader($pdo, $clock),
            new PdoProductCommandRepository($pdo),
            new PdoProductConfigurationRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Option Consumer service: the selectable Options of a Product.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionConsumerService(PDO $pdo, ClockInterface $clock): OptionConsumerServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new OptionConsumerService(new PdoOptionQueryReader($pdo, $clock));
    }

    /**
     * Builds the Option Value Management service: creation, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionValueManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): OptionValueManagementServiceInterface
    {
        self::requireExceptionMode($pdo);
        $ordering = new ScopedOrderingManager();

        return new OptionValueManagementService(
            new PdoOptionValueCommandRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoOptionValueQueryReader($pdo, $clock),
            new PdoOptionCommandRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoProductCommandRepository($pdo),
            new PdoProductConfigurationRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Option Value Consumer service: the selectable Values of an Option.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionValueConsumerService(PDO $pdo, ClockInterface $clock): OptionValueConsumerServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new OptionValueConsumerService(new PdoOptionValueQueryReader($pdo, $clock));
    }

    /**
     * Builds the Image Role Management service: creation, lifecycle, and management reads. Image
     * Roles have no consumer read.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createImageRoleManagementService(PDO $pdo, ClockInterface $clock): ImageRoleManagementServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new ImageRoleManagementService(
            new PdoImageRoleCommandRepository($pdo),
            new PdoImageRoleQueryReader($pdo, $clock),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Image Assignment Management service: creation, default operations, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createImageAssignmentManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): ImageAssignmentManagementServiceInterface
    {
        self::requireExceptionMode($pdo);
        $ordering = new ScopedOrderingManager();

        return new ImageAssignmentManagementService(
            new PdoImageAssignmentCommandRepository($pdo, $ordering, $ownerLookupPdo),
            new PdoImageAssignmentQueryReader($pdo, $clock),
            new PdoProductCommandRepository($pdo),
            new PdoVariantCommandRepository($pdo, $ordering),
            new PdoImageRoleCommandRepository($pdo),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    /**
     * Builds the Image Assignment Consumer service: the visible assignments of one exact scope.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createImageAssignmentConsumerService(PDO $pdo, ClockInterface $clock): ImageAssignmentConsumerServiceInterface
    {
        self::requireExceptionMode($pdo);

        return new ImageAssignmentConsumerService(new PdoImageAssignmentQueryReader($pdo, $clock));
    }

    /**
     * Builds the Product Content Management service: name and descriptions per language, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createProductContentManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): ProductContentManagementServiceInterface
    {
        return new ProductContentManagementService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::PRODUCT, $ownerLookupPdo));
    }

    /**
     * Builds the Product Content Consumer service: the visible record of one exact language.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createProductContentConsumerService(PDO $pdo, ClockInterface $clock): ProductContentConsumerServiceInterface
    {
        return new ProductContentConsumerService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::PRODUCT));
    }

    /**
     * Builds the Option Content Management service: labels per language, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionContentManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): OptionContentManagementServiceInterface
    {
        return new OptionContentManagementService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::OPTION, $ownerLookupPdo));
    }

    /**
     * Builds the Option Content Consumer service: the visible record of one exact language.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionContentConsumerService(PDO $pdo, ClockInterface $clock): OptionContentConsumerServiceInterface
    {
        return new OptionContentConsumerService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::OPTION));
    }

    /**
     * Builds the Option Value Content Management service: labels per language, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionValueContentManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): OptionValueContentManagementServiceInterface
    {
        return new OptionValueContentManagementService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::OPTION_VALUE, $ownerLookupPdo));
    }

    /**
     * Builds the Option Value Content Consumer service: the visible record of one exact language.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createOptionValueContentConsumerService(PDO $pdo, ClockInterface $clock): OptionValueContentConsumerServiceInterface
    {
        return new OptionValueContentConsumerService(self::contentLifecycle($pdo, $clock, ContentOwnerEnum::OPTION_VALUE));
    }

    private static function contentLifecycle(PDO $pdo, ClockInterface $clock, ContentOwnerEnum $owner, ?PDO $ownerLookupPdo = null): ContentLifecycle
    {
        self::requireExceptionMode($pdo);

        return new ContentLifecycle(
            $owner,
            new PdoContentCommandRepository($pdo, $owner, $ownerLookupPdo),
            new PdoContentQueryReader($pdo, $owner, $clock),
            new PdoProductConfigurationRepository($pdo, new ScopedOrderingManager()),
            new PdoSavepointTransactionRunner($pdo),
            $clock,
        );
    }

    private static function requireExceptionMode(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw ProductInvalidArgumentException::pdoMustThrowExceptions();
        }
    }
}
