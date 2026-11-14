<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Common\Exception;

use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Exception\ProductExceptionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The consumer-facing contract of ProductPersistenceException is its type and the package
 * marker only; it exposes no named constructors, and the internal factory raises it.
 */
final class ProductPersistenceExceptionTest extends TestCase
{
    public function testTheTypeIsTheWholeConsumerContract(): void
    {
        $class = new ReflectionClass(ProductPersistenceException::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->implementsInterface(ProductExceptionInterface::class));

        $raised = (static fn(): Throwable => ProductStorageFailureFactory::invalidStorageValue('status'))();
        self::assertInstanceOf(SystemMaatifyException::class, $raised);
    }

    public function testItDeclaresNoPublicNamedConstructorsOfItsOwn(): void
    {
        $own = array_filter(
            (new ReflectionClass(ProductPersistenceException::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => $method->class === ProductPersistenceException::class,
        );

        self::assertSame([], array_map(static fn(ReflectionMethod $method): string => $method->name, array_values($own)));
    }

    public function testTheInternalFactoryIsNotInstantiableAndRaisesTheDocumentedFailures(): void
    {
        self::assertFalse((new ReflectionClass(ProductStorageFailureFactory::class))->isInstantiable());

        $failures = [
            ProductStorageFailureFactory::invalidAutoIncrementIdentity('Product'),
            ProductStorageFailureFactory::invalidStorageValue('status'),
            ProductStorageFailureFactory::unexpectedColumnType('created_at'),
        ];

        foreach ($failures as $failure) {
            self::assertInstanceOf(ProductPersistenceException::class, $failure);
            self::assertInstanceOf(ProductExceptionInterface::class, $failure);
        }
        self::assertSame('Product Product AUTO_INCREMENT did not return a valid identity.', $failures[0]->getMessage());
        self::assertSame('Product storage column "status" contains an invalid value.', $failures[1]->getMessage());
        self::assertSame('Product storage column "created_at" has an unexpected type.', $failures[2]->getMessage());
    }
}
