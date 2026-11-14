<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

use PDOException;

/**
 * Classification of MySQL duplicate-key errors (driver code 1062) for the package's conflict
 * converters. The key is read only from the end of the driver message, `... for key '<key>'`, never
 * from the duplicated value earlier in the message, so a value that happens to contain a key name
 * cannot change the classification. MySQL 8.0.16 to 8.0.18 name the key `<key>` and 8.0.19 and later
 * `<table>.<key>`; both forms yield `<key>`.
 *
 * @internal Not part of the Public Runtime API.
 */
final class DuplicateKey
{
    private const int MYSQL_DUPLICATE_KEY = 1062;

    private function __construct() {}

    /**
     * Whether the exception is a duplicate-key error.
     */
    public static function isDuplicate(PDOException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return (is_int($driverCode) || is_string($driverCode)) && (int) $driverCode === self::MYSQL_DUPLICATE_KEY;
    }

    /**
     * The name of the violated unique key of a duplicate-key error, without a table prefix, or null for
     * any other error or a message without a trailing key clause.
     */
    public static function keyName(PDOException $exception): ?string
    {
        if (!self::isDuplicate($exception)) {
            return null;
        }
        $message = $exception->errorInfo[2] ?? null;
        if (!is_string($message) || preg_match("/ for key '(?:[^'.]*\\.)?([^'.]+)'\\z/", $message, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Whether the exception is a duplicate-key error on exactly the named unique key.
     */
    public static function isOnKey(PDOException $exception, string $key): bool
    {
        return self::keyName($exception) === $key;
    }
}
