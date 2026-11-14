<?php

declare(strict_types=1);

require __DIR__ . '/php-patch-floors.php';

$expected = $argv[1] ?? '';
$minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$floors = pinnedPhpPatchFloors();
printf("Installed PHP: %s (PHP_VERSION_ID=%d; expected minor %s)\n", PHP_VERSION, PHP_VERSION_ID, $expected);
if ($minor !== $expected || !isset($floors[$minor]) || version_compare(PHP_VERSION, $floors[$minor], '<')) {
    fwrite(STDERR, "Installed PHP does not meet the expected minor and pinned patch floor.\n");
    exit(1);
}
