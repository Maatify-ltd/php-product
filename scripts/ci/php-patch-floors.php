<?php

declare(strict_types=1);

/** @return non-empty-array<string, string> */
function pinnedPhpPatchFloors(): array
{
    $standard = file_get_contents(__DIR__ . '/../../docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md');
    if ($standard === false || preg_match('/^#### 15\.1\.1 PHP Security Patch Floors\R(.*?)(?=^### )/ms', $standard, $section) !== 1) {
        throw new RuntimeException('Cannot read the pinned PHP security patch floors.');
    }

    preg_match_all('/^\| (\d+\.\d+) \| (\d+\.\d+\.\d+) \|/m', $section[1], $rows, PREG_SET_ORDER);
    $floors = [];
    foreach ($rows as [, $minor, $floor]) {
        if (!str_starts_with($floor, $minor . '.') || isset($floors[$minor])) {
            throw new RuntimeException('Invalid PHP security patch floor for ' . $minor);
        }
        $floors[$minor] = $floor;
    }
    if ($floors === []) {
        throw new RuntimeException('The pinned PHP security patch floors are empty.');
    }

    return $floors;
}
