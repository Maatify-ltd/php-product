<?php

/**
 * Child process for the deadlock test, playing a second Host transaction on its own connection.
 *
 * It locks the first Product row, modifies the weight rows (so InnoDB, which rolls back the
 * transaction with the smaller weight, never chooses this one as the deadlock victim), reports
 * `locked` on stdout, and then requests the second Product row, which the parent's Host transaction
 * holds. It finally rolls back and prints the outcome of its own lock request.
 *
 * Usage: php deadlock-worker.php <first-product-id> <second-product-id> <comma-separated-weight-product-ids>
 */

declare(strict_types=1);

/** @var list<string> $argv */
$first = (int) ($argv[1] ?? '0');
$second = (int) ($argv[2] ?? '0');
$weightIds = array_map(intval(...), explode(',', $argv[3] ?? ''));

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? $value : '';
};

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('PRODUCT_TEST_DB_HOST'), $env('PRODUCT_TEST_DB_PORT'), $env('PRODUCT_TEST_DB_NAME')),
    $env('PRODUCT_TEST_DB_USER'),
    $env('PRODUCT_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);

$pdo->beginTransaction();
$pdo->prepare('SELECT `id` FROM `maa_product_products` WHERE `id` = :id FOR UPDATE')->execute(['id' => $first]);
$weight = $pdo->prepare("UPDATE `maa_product_products` SET `updated_at` = '2030-01-01 00:00:00' WHERE `id` = :id");
foreach ($weightIds as $weightId) {
    $weight->execute(['id' => $weightId]);
}

fwrite(STDOUT, "locked\n");
fflush(STDOUT);

try {
    $pdo->prepare('SELECT `id` FROM `maa_product_products` WHERE `id` = :id FOR UPDATE')->execute(['id' => $second]);
    echo 'acquired';
} catch (PDOException $e) {
    $driverCode = is_int($e->errorInfo[1] ?? null) ? (string) $e->errorInfo[1] : '';
    echo 'error:' . $driverCode . ':' . $e->getMessage();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
