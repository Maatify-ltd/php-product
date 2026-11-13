<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Identity produced by creating a Option Value Content record.
 */
final readonly class CreatedOptionValueContentDTO implements JsonSerializable
{
    public function __construct(public int $contentId)
    {
        if ($contentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('contentId');
        }
    }

    /** @return array{contentId: int} */
    public function jsonSerialize(): array
    {
        return ['contentId' => $this->contentId];
    }
}
