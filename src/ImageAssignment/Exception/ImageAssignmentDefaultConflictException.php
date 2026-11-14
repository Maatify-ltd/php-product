<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when a soft-deleted Image Assignment is restored as the default of its exact
 * `(target, role, language, platform)` scope while that scope already has a live default.
 * Restore-as-default never replaces an existing default; nothing is written.
 */
final class ImageAssignmentDefaultConflictException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    /**
     * @param int|null $defaultAssignmentId The live default of the scope, when it could be identified.
     */
    public static function forRestoreAsDefault(int $assignmentId, ?int $defaultAssignmentId, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Image Assignment [%d] cannot be restored as default: its exact scope already has the live default %s.',
            $assignmentId,
            $defaultAssignmentId === null ? 'assignment' : sprintf('Image Assignment [%d]', $defaultAssignmentId),
        ), 0, $previous);
    }
}
