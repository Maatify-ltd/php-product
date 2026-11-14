<?php

/**
 * Smoke-executes every standalone example in examples/ against the repository-owned MySQL service.
 *
 * Usage: php scripts/ci/smoke-examples.php <package-root>
 * Environment: PRODUCT_TEST_DB_HOST, PRODUCT_TEST_DB_PORT, PRODUCT_TEST_DB_USER,
 * PRODUCT_TEST_DB_PASSWORD (set by scripts/ci/mysql-service.sh) and PRODUCT_EXAMPLE_AUTOLOAD, the
 * production Composer autoloader of the consumer root that installed the package. A fresh
 * database with schema/product.sql installed is created for the run and dropped afterwards.
 * Every example must exit 0 and finish with an `OK: example` line.
 */

declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root . '/examples')) {
    fwrite(STDERR, "Usage: php smoke-examples.php <package-root>\n");
    exit(2);
}

$env = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Missing environment variable {$name}\n");
        exit(2);
    }

    return $value;
};

$host = $env('PRODUCT_TEST_DB_HOST');
$port = $env('PRODUCT_TEST_DB_PORT');
$user = $env('PRODUCT_TEST_DB_USER');
$password = $env('PRODUCT_TEST_DB_PASSWORD');
$autoload = $env('PRODUCT_EXAMPLE_AUTOLOAD');
if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader not found: {$autoload}\n");
    exit(2);
}

$examples = glob($root . '/examples/*.php');
if ($examples === false || $examples === []) {
    fwrite(STDERR, "No examples found.\n");
    exit(1);
}
sort($examples);

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$base = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
$admin = new PDO($base, $user, $password, $options);
$database = 'product_example_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `{$database}`");

$status = 0;
try {
    $pdo = new PDO($base . ';dbname=' . $database, $user, $password, $options);
    $sql = (string) file_get_contents($root . '/schema/product.sql');
    foreach (explode(";\n", (string) preg_replace('/^--.*$/m', '', $sql)) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec(trim($statement));
        }
    }

    foreach ($examples as $example) {
        $command = [PHP_BINARY, $example];
        $environment = [
            'PRODUCT_EXAMPLE_DSN' => $base . ';dbname=' . $database,
            'PRODUCT_EXAMPLE_USER' => $user,
            'PRODUCT_EXAMPLE_PASSWORD' => $password,
            'PRODUCT_EXAMPLE_AUTOLOAD' => $autoload,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) {
            fwrite(STDERR, "FAIL: could not start {$example}\n");
            $status = 1;
            continue;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout)), static fn(string $line): bool => $line !== ''));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if ($code !== 0 || !str_starts_with($last, 'OK: example')) {
            fwrite(STDERR, "FAIL: {$example} (exit {$code})\n{$stdout}{$stderr}");
            $status = 1;
            continue;
        }
        echo 'ok: ' . basename($example) . ' - ' . $last . "\n";
    }
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

exit($status);
