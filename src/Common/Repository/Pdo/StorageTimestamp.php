<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;

/**
 * Encodes application instants as UTC in MySQL DATETIME(6), independent of both the PDO session
 * timezone and the Host's presentation timezone.
 *
 * @internal Not part of the Public Runtime API.
 */
final class StorageTimestamp
{
    private const string FORMAT = 'Y-m-d H:i:s.u';

    private function __construct() {}

    /** Encodes the supplied instant without losing its microseconds. */
    public static function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /** Reads the stored UTC representation and rejects malformed or normalized dates. */
    public static function parse(string $value, string $column): DateTimeImmutable
    {
        try {
            $timestamp = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            throw ProductStorageFailureFactory::invalidStorageValue($column, $exception);
        }

        if (DateTimeImmutable::getLastErrors() !== false
            || strlen($value) !== 26
            || $value[0] === '0'
            || $timestamp->format(self::FORMAT) !== $value
        ) {
            throw ProductStorageFailureFactory::invalidStorageValue($column);
        }

        return $timestamp;
    }
}
