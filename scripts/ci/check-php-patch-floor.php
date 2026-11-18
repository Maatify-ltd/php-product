<?php

declare(strict_types=1);

require __DIR__ . '/php-patch-floors.php';

$expected = $argv[1] ?? '';
$floors = pinnedPhpPatchFloors();
printf("Installed PHP: %s (PHP_VERSION_ID=%d; expected minor %s)\n", PHP_VERSION, PHP_VERSION_ID, $expected);
if (!isStablePhpPatchAtFloor(PHP_VERSION, $expected, $floors[$expected] ?? null)) {
    fwrite(STDERR, "Installed PHP must be a stable patch of the expected minor at or above the pinned floor.\n");
    exit(1);
}
