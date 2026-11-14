<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when the `(Option, language)` identity of a Content record is already taken, including by
 * a soft-deleted record and by the single unlocalized record; the explicit restore is the only way back.
 */
final class OptionContentAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function forIdentity(int $ownerId, ?string $languageCode, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Option Content for Option [%d] with language %s already exists.',
            $ownerId,
            $languageCode === null ? 'NULL' : '"' . $languageCode . '"',
        ), 0, $previous);
    }
}
