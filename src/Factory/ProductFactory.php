<?php

declare(strict_types=1);

namespace Maatify\Product\Factory;

use Closure;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Repository\Pdo\OwnerIdentityLookup;
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
 * has been installed, and its Clock. Every service built on one connection shares its transactions: a
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
    /** @param PDO|(Closure(): PDO)|null $ownerLookup */
    private function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly PDO|Closure|null $ownerLookup = null,
    ) {}

    /**
     * Configures the Management builders for one Host connection. The first lookup miss opens
     * an independent autocommit session on the same primary and schema; builders share it.
     * The lookup PDO must be separate from the Host PDO, idle, and in exception mode.
     *
     * The first operation that needs lookup rejects an unusable PDO with
     * ProductInvalidArgumentException. The rejected connection is not cached, so a later
     * lookup can call the opener again. Failure inside a Host transaction is scoped to the
     * operation's savepoint; the Host retains control of its transaction.
     *
     * @param (callable(): PDO)|null $ownerLookup Opens the independent lookup connection on demand.
     * @throws ProductInvalidArgumentException When the Host PDO is not in exception mode.
     */
    public static function forHost(PDO $pdo, ClockInterface $clock, ?callable $ownerLookup = null): self
    {
        self::requireExceptionMode($pdo);
        if ($ownerLookup === null) {
            return new self($pdo, $clock);
        }

        $lookup = null;
        $open = static function () use ($pdo, $ownerLookup, &$lookup): PDO {
            if ($lookup === null) {
                $connection = $ownerLookup();
                OwnerIdentityLookup::requireUsableConnection($pdo, $connection);
                $lookup = $connection;
            }

            return $lookup;
        };

        return new self($pdo, $clock, $open);
    }

    /**
     * Builds the Product Management service: creation, management reads, lifecycle, and reconfiguration.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createProductManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): ProductManagementServiceInterface
    {
        self::requireExceptionMode($pdo);

        return (new self($pdo, $clock, $ownerLookupPdo))->products();
    }

    /** Builds the Product Management service for this Host. */
    public function products(): ProductManagementServiceInterface
    {
        $ordering = new ScopedOrderingManager();

        return new ProductManagementService(
            new PdoProductCommandRepository($this->pdo),
            new PdoVariantCommandRepository($this->pdo, $ordering),
            new PdoProductQueryReader($this->pdo, $this->clock),
            new PdoProductConfigurationRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoSavepointTransactionRunner($this->pdo),
            $this->clock,
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

        return (new self($pdo, $clock, $ownerLookupPdo))->variants();
    }

    /** Builds the Variant Management service for this Host. */
    public function variants(): VariantManagementServiceInterface
    {
        $ordering = new ScopedOrderingManager();

        return new VariantManagementService(
            new PdoVariantQueryReader($this->pdo, $this->clock),
            new PdoVariantCommandRepository($this->pdo, $ordering),
            new PdoProductConfigurationRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoSavepointTransactionRunner($this->pdo),
            $this->clock,
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

        return (new self($pdo, $clock, $ownerLookupPdo))->options();
    }

    /** Builds the Option Management service for this Host. */
    public function options(): OptionManagementServiceInterface
    {
        $ordering = new ScopedOrderingManager();

        return new OptionManagementService(
            new PdoOptionCommandRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoOptionQueryReader($this->pdo, $this->clock),
            new PdoProductCommandRepository($this->pdo),
            new PdoProductConfigurationRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoSavepointTransactionRunner($this->pdo),
            $this->clock,
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

        return (new self($pdo, $clock, $ownerLookupPdo))->optionValues();
    }

    /** Builds the OptionValue Management service for this Host. */
    public function optionValues(): OptionValueManagementServiceInterface
    {
        $ordering = new ScopedOrderingManager();

        return new OptionValueManagementService(
            new PdoOptionValueCommandRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoOptionValueQueryReader($this->pdo, $this->clock),
            new PdoOptionCommandRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoProductCommandRepository($this->pdo),
            new PdoProductConfigurationRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoSavepointTransactionRunner($this->pdo),
            $this->clock,
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

    /** Builds the ImageRole Management service for this Host. */
    public function imageRoles(): ImageRoleManagementServiceInterface
    {
        return self::createImageRoleManagementService($this->pdo, $this->clock);
    }

    /**
     * Builds the Image Assignment Management service: creation, default operations, lifecycle, and management reads.
     *
     * @throws ProductInvalidArgumentException When the connection does not report errors as exceptions.
     */
    public static function createImageAssignmentManagementService(PDO $pdo, ClockInterface $clock, ?PDO $ownerLookupPdo = null): ImageAssignmentManagementServiceInterface
    {
        self::requireExceptionMode($pdo);

        return (new self($pdo, $clock, $ownerLookupPdo))->imageAssignments();
    }

    /** Builds the ImageAssignment Management service for this Host. */
    public function imageAssignments(): ImageAssignmentManagementServiceInterface
    {
        $ordering = new ScopedOrderingManager();

        return new ImageAssignmentManagementService(
            new PdoImageAssignmentCommandRepository($this->pdo, $ordering, $this->ownerLookup),
            new PdoImageAssignmentQueryReader($this->pdo, $this->clock),
            new PdoProductCommandRepository($this->pdo),
            new PdoVariantCommandRepository($this->pdo, $ordering),
            new PdoImageRoleCommandRepository($this->pdo),
            new PdoSavepointTransactionRunner($this->pdo),
            $this->clock,
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
        self::requireExceptionMode($pdo);

        return (new self($pdo, $clock, $ownerLookupPdo))->productContent();
    }

    /** Builds the ProductContent Management service for this Host. */
    public function productContent(): ProductContentManagementServiceInterface
    {
        return new ProductContentManagementService(self::contentLifecycle($this->pdo, $this->clock, ContentOwnerEnum::PRODUCT, $this->ownerLookup));
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
        self::requireExceptionMode($pdo);

        return (new self($pdo, $clock, $ownerLookupPdo))->optionContent();
    }

    /** Builds the OptionContent Management service for this Host. */
    public function optionContent(): OptionContentManagementServiceInterface
    {
        return new OptionContentManagementService(self::contentLifecycle($this->pdo, $this->clock, ContentOwnerEnum::OPTION, $this->ownerLookup));
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
        self::requireExceptionMode($pdo);

        return (new self($pdo, $clock, $ownerLookupPdo))->optionValueContent();
    }

    /** Builds the OptionValueContent Management service for this Host. */
    public function optionValueContent(): OptionValueContentManagementServiceInterface
    {
        return new OptionValueContentManagementService(self::contentLifecycle($this->pdo, $this->clock, ContentOwnerEnum::OPTION_VALUE, $this->ownerLookup));
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

    /** @param PDO|(Closure(): PDO)|null $ownerLookupPdo */
    private static function contentLifecycle(PDO $pdo, ClockInterface $clock, ContentOwnerEnum $owner, PDO|Closure|null $ownerLookupPdo = null): ContentLifecycle
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
