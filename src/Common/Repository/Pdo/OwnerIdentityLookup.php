<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use PDO;

/**
 * Reads immutable owner identities without locking a child before its Product.
 *
 * @internal
 */
final readonly class OwnerIdentityLookup
{
    public function __construct(
        private PDO $transactionConnection,
        private ?PDO $lookupConnection,
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
        if ($this->lookupConnection === $this->transactionConnection
            || $this->lookupConnection->inTransaction()
            || !$this->lookupConnection->getAttribute(PDO::ATTR_AUTOCOMMIT)
            || $this->lookupConnection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
        ) {
            throw ProductInvalidArgumentException::invalidOwnerLookupConnection();
        }

        return self::read($this->lookupConnection, $sql, $id);
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
