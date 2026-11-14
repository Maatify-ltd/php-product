<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to move one Image Assignment to a position within its exact `(target, role, language, platform)` scope. A position above the
 * current maximum is clamped to it by the shared Ordering API.
 */
final readonly class UpdateImageAssignmentDisplayOrderCommand implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity or the position is not a positive integer.
     */
    public function __construct(
        public int $assignmentId,
        public int $displayOrder,
    ) {
        if ($assignmentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('assignmentId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /** @return array{assignmentId: int, displayOrder: int} */
    public function jsonSerialize(): array
    {
        return ['assignmentId' => $this->assignmentId, 'displayOrder' => $this->displayOrder];
    }
}
