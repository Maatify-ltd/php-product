<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * Validated intent to replace the name of one Option Value Content record. The owner and the
 * language are the record's immutable identity and are not part of the command.
 */
final readonly class UpdateOptionValueContentCommand implements JsonSerializable
{
    /**
     * @param int $contentId The Option Value Content record.
     * @param string $name Required, valid UTF-8, not empty or whitespace only, at most 255 characters; never trimmed.
     *
     * @throws ProductInvalidArgumentException When a value violates its contract.
     */
    public function __construct(
        public int $contentId,
        public string $name,
    ) {
        if ($contentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('contentId');
        }
        TextInputRules::requiredText($name, 'name', CreateOptionValueContentCommand::MAX_NAME_LENGTH);
    }

    /** @return array{contentId: int, name: string} */
    public function jsonSerialize(): array
    {
        return ['contentId' => $this->contentId, 'name' => $this->name];
    }
}
