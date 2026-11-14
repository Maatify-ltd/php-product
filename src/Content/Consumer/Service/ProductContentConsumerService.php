<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Consumer\Service;

use Maatify\Product\Content\DTO\ProductContentCollectionDTO;
use Maatify\Product\Content\Mapper\ContentRecordMapper;
use Maatify\Product\Content\Service\ContentLifecycle;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Product Content consumer read: delegates to the shared {@see ContentLifecycle} and maps its records.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ProductContentConsumerService implements ProductContentConsumerServiceInterface
{
    public function __construct(private ContentLifecycle $content) {}

    public function listVisible(int $productId, ExactContentLanguage $language): ProductContentCollectionDTO
    {
        return new ProductContentCollectionDTO(array_map(ContentRecordMapper::toProductContent(...), $this->content->listVisible($productId, $language)));
    }
}
