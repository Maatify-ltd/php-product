<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Consumer\Service;

use Maatify\Product\Content\DTO\OptionContentCollectionDTO;
use Maatify\Product\Content\Mapper\ContentRecordMapper;
use Maatify\Product\Content\Service\ContentLifecycle;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Option Content consumer read: delegates to the shared {@see ContentLifecycle} and maps its records.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionContentConsumerService implements OptionContentConsumerServiceInterface
{
    public function __construct(private ContentLifecycle $content) {}

    public function listVisible(int $optionId, ExactContentLanguage $language): OptionContentCollectionDTO
    {
        return new OptionContentCollectionDTO(array_map(ContentRecordMapper::toOptionContent(...), $this->content->listVisible($optionId, $language)));
    }
}
