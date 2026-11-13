<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

use Closure;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use PDO;

/**
 * Reads immutable owner identities without locking a child before its Product.
 *
 * @internal
 */
final readonly class OwnerIdentityLookup
{
    /** @param PDO|(Closure(): PDO)|null $lookupConnection */
    public function __construct(
        private PDO $transactionConnection,
        private PDO|Closure|null $lookupConnection,
    ) {}

    /**
     * Prefer the transaction's own view so uncommitted Host inserts are visible.
     * A miss can be an older read view. Retry on the Host's independent primary session.
     *
     * @return array<string, mixed>|false
     */
    public function find(string $sql, int $id): array|false
    {
        $row = self::read($this->transactionConnection, $sql, $id);
        if ($row !== false || $this->lookupConnection === null) {
            return $row;
        }
        $connection = $this->lookupConnection instanceof Closure ? ($this->lookupConnection)() : $this->lookupConnection;
        self::requireUsableConnection($this->transactionConnection, $connection);

        return self::read($connection, $sql, $id);
    }

    public static function requireUsableConnection(PDO $transactionConnection, PDO $lookupConnection): void
    {
        if ($lookupConnection === $transactionConnection
            || $lookupConnection->inTransaction()
            || !$lookupConnection->getAttribute(PDO::ATTR_AUTOCOMMIT)
            || $lookupConnection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
        ) {
            throw ProductInvalidArgumentException::invalidOwnerLookupConnection();
        }
    }

    /** @return array<string, mixed>|false */
    private static function read(PDO $pdo, string $sql, int $id): array|false
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row;
    }
}
