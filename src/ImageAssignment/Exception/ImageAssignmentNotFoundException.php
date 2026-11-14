<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when an Image Assignment identity does not exist, or does not match the deleted state of the read that asked.
 */
final class ImageAssignmentNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $assignmentId): self
    {
        return new self(sprintf('Image Assignment [%d] was not found.', $assignmentId));
    }
}
