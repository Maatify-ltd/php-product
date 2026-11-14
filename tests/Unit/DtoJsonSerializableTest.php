<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use FilesystemIterator;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Package Building Standard section 9: every DTO implements `JsonSerializable`. The test scans every
 * PHP file under `src/` whose type name ends in `DTO`, so a new DTO that does not implement the
 * interface fails here; the count guard keeps the scan from silently finding nothing.
 */
final class DtoJsonSerializableTest extends TestCase
{
    private const string ROOT_NAMESPACE = 'Maatify\\Product\\';

    public function testEveryDtoImplementsJsonSerializable(): void
    {
        $dtos = $this->dtoTypes();
        $failures = [];
        foreach ($dtos as $type) {
            if (!class_exists($type)) {
                $failures[] = $type . ' does not autoload';

                continue;
            }
            if (!is_subclass_of($type, JsonSerializable::class)) {
                $failures[] = $type . ' does not implement JsonSerializable';
            }
        }

        self::assertGreaterThan(50, count($dtos), 'The scan must find the package DTOs.');
        self::assertSame([], $failures);
    }

    /** @return list<string> */
    private function dtoTypes(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $types = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && str_ends_with($file->getBasename('.php'), 'DTO')) {
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $types[] = self::ROOT_NAMESPACE . str_replace('/', '\\', $relative);
            }
        }
        sort($types);

        return $types;
    }
}
