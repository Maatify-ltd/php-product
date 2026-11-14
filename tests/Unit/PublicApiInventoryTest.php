<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use FilesystemIterator;
use Maatify\Product\Factory\ProductFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Conformity between the Package Reference and the code, with the code as the second source of
 * truth rather than a second list: a package type is public exactly when the "Implemented Public
 * API" table lists it, and internal exactly when its own docblock carries `@internal`. So a new
 * type must be classified in the code, a listed type may not be tagged internal, every method
 * named for a service or the factory must exist and every public method of those types must be
 * named, and every capability row of the inventory (`PRD-01`, `IAS-R2`, and so on) must name at
 * least one method, each of which must exist.
 */
final class PublicApiInventoryTest extends TestCase
{
    private const string ROOT_NAMESPACE = 'Maatify\\Product\\';

    public function testEveryListedTypeExistsAndEveryServiceMethodIsDeclared(): void
    {
        $missing = [];
        foreach ($this->rows() as [$classes, $methods]) {
            foreach ($classes as $class) {
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                    $missing[] = 'type ' . $class;
                }
            }
            if ($methods === []) {
                continue;
            }
            $declared = [];
            foreach ($classes as $class) {
                if (!class_exists($class) && !interface_exists($class)) {
                    continue;
                }
                foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->class === $class && !$method->isConstructor()) {
                        $declared[] = $method->getName();
                    }
                }
            }
            foreach ($methods as $name) {
                if (!in_array($name, $declared, true)) {
                    $missing[] = 'method ' . $name;
                }
            }
            foreach ($declared as $name) {
                if (!in_array($name, $methods, true)) {
                    $missing[] = 'undocumented public method ' . $name . ' of ' . implode(', ', $classes);
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryPackageTypeIsListedXorTaggedInternal(): void
    {
        $listed = [];
        foreach ($this->rows() as [$classes]) {
            foreach ($classes as $class) {
                $listed[$class] = true;
            }
        }

        $problems = [];
        foreach ($this->packageTypes() as $type) {
            $internal = $this->isInternal($type);
            if (isset($listed[$type]) && $internal) {
                $problems[] = $type . ' is listed as public but tagged @internal';
            }
            if (!isset($listed[$type]) && !$internal) {
                $problems[] = $type . ' is neither listed in the Package Reference nor tagged @internal';
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryCapabilityRowNamesExistingMethods(): void
    {
        $reference = (string) file_get_contents(dirname(__DIR__, 2) . '/PRODUCT_PACKAGE_REFERENCE.md');
        $start = strpos($reference, '## Approved Contract Inventory');
        $end = strpos($reference, '## Operational Read / Reporting Classification');
        self::assertIsInt($start);
        self::assertIsInt($end);

        $methods = [];
        foreach ($this->rows() as [$classes]) {
            foreach ($classes as $class) {
                if (!class_exists($class) && !interface_exists($class)) {
                    continue;
                }
                foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $methods[$method->getName()] = true;
                }
            }
        }

        $missing = [];
        $rows = 0;
        foreach (explode("\n", substr($reference, $start, $end - $start)) as $line) {
            if (preg_match('/^\| `((?:PRD|VAR|OPT|OVL|CNT|IRL|IAS)-R?\d+)` \|/', $line, $id) !== 1) {
                continue;
            }
            ++$rows;
            preg_match_all('/`(?:\w+::)?([a-z]\w*)\(/', $line, $names);
            if ($names[1] === []) {
                $missing[] = $id[1] . ' names no method';
            }
            foreach ($names[1] as $name) {
                if (!isset($methods[$name])) {
                    $missing[] = $id[1] . ' names the missing method ' . $name;
                }
            }
        }

        // PRD (6), VAR (8), OPT (7), OVL (7), CNT (6), IRL (5), IAS (9).
        self::assertSame(48, $rows, 'Every capability row of the inventory must be checked.');
        self::assertSame([], $missing);
    }

    /**
     * Each capability has separate Management and Consumer contracts and no single combined service:
     * no combined service type or factory method may exist.
     */
    public function testTheFormerSingleServiceContractsNoLongerExist(): void
    {
        $former = [
            'Product\\Service\\ProductServiceInterface',
            'Product\\Service\\ProductService',
            'Variant\\Service\\VariantServiceInterface',
            'Variant\\Service\\VariantService',
            'Option\\Service\\OptionServiceInterface',
            'Option\\Service\\OptionService',
            'OptionValue\\Service\\OptionValueServiceInterface',
            'OptionValue\\Service\\OptionValueService',
            'ImageRole\\Service\\ImageRoleServiceInterface',
            'ImageRole\\Service\\ImageRoleService',
            'ImageAssignment\\Service\\ImageAssignmentServiceInterface',
            'ImageAssignment\\Service\\ImageAssignmentService',
            'Content\\Service\\ProductContentServiceInterface',
            'Content\\Service\\ProductContentService',
            'Content\\Service\\OptionContentServiceInterface',
            'Content\\Service\\OptionContentService',
            'Content\\Service\\OptionValueContentServiceInterface',
            'Content\\Service\\OptionValueContentService',
        ];
        $remaining = array_values(array_intersect(
            array_map(static fn(string $type): string => self::ROOT_NAMESPACE . $type, $former),
            $this->packageTypes(),
        ));

        $factoryMethods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ProductFactory::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        foreach (['Product', 'Variant', 'Option', 'OptionValue', 'ImageRole', 'ImageAssignment', 'ProductContent', 'OptionContent', 'OptionValueContent'] as $capability) {
            if (in_array('create' . $capability . 'Service', $factoryMethods, true)) {
                $remaining[] = 'ProductFactory::create' . $capability . 'Service';
            }
        }
        if (in_array('createImageRoleConsumerService', $factoryMethods, true)) {
            $remaining[] = 'ProductFactory::createImageRoleConsumerService';
        }

        self::assertSame([], $remaining);
    }

    private function isInternal(string $type): bool
    {
        if (!class_exists($type) && !interface_exists($type) && !enum_exists($type)) {
            self::fail('Package type does not autoload: ' . $type);
        }
        $doc = (new ReflectionClass($type))->getDocComment();

        return is_string($doc) && str_contains($doc, '@internal');
    }

    /**
     * @return list<array{list<class-string>, list<string>}> Types and the method names of each table row.
     */
    private function rows(): array
    {
        $reference = (string) file_get_contents(dirname(__DIR__, 2) . '/PRODUCT_PACKAGE_REFERENCE.md');
        $start = strpos($reference, '## Implemented Public API');
        $end = strpos($reference, '### Behavior and guarantees');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $section = substr($reference, $start, $end - $start);

        $rows = [];
        foreach (explode("\n", $section) as $line) {
            $cells = array_map('trim', explode('|', trim($line, " \t|")));
            if (!str_starts_with($line, '| `') || count($cells) < 2) {
                continue;
            }
            preg_match_all('/`([A-Za-z]+(?:\\\\[A-Za-z]+)+)`/', $cells[0], $types);
            /** @var list<class-string> $classes */
            $classes = array_map(static fn(string $type): string => self::ROOT_NAMESPACE . $type, $types[1]);
            preg_match_all('/`(\w+)\(/', $cells[1], $methods);
            $rows[] = [$classes, array_values(array_unique($methods[1]))];
        }
        self::assertNotSame([], $rows);

        return $rows;
    }

    /** @return list<string> */
    private function packageTypes(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $types = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $types[] = self::ROOT_NAMESPACE . str_replace('/', '\\', $relative);
            }
        }
        sort($types);

        return $types;
    }
}
