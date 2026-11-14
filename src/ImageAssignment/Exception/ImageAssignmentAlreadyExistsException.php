<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Throwable;

/**
 * Raised when the `(target, media asset, role, language, platform)` identity of an Image Assignment is
 * already taken, including by a soft-deleted assignment; the explicit restore is the only way back.
 */
final class ImageAssignmentAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function forIdentity(CreateImageAssignmentCommand $command, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Image Assignment of media asset [%d] to %s with role %s, language %s, platform %s already exists.',
            $command->mediaAssetId,
            $command->variantId === null ? sprintf('Product [%d]', $command->productId) : sprintf('Variant [%d]', $command->variantId),
            $command->roleId === null ? 'NULL' : sprintf('[%d]', $command->roleId),
            $command->languageCode === null ? 'NULL' : '"' . $command->languageCode . '"',
            $command->platform === null ? 'NULL' : '"' . $command->platform . '"',
        ), 0, $previous);
    }
}
