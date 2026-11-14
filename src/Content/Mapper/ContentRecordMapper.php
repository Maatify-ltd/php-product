<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Mapper;

use Maatify\Product\Content\DTO\ContentRecordDTO;
use Maatify\Product\Content\DTO\OptionContentDTO;
use Maatify\Product\Content\DTO\OptionValueContentDTO;
use Maatify\Product\Content\DTO\ProductContentDTO;

/**
 * Maps the owner-neutral Content records of the shared lifecycle to each owner's public DTO, for
 * both the Management and the Consumer services.
 *
 * @internal Not part of the Public Runtime API.
 */
final class ContentRecordMapper
{
    private function __construct() {}

    public static function toProductContent(ContentRecordDTO $record): ProductContentDTO
    {
        return new ProductContentDTO(
            id: $record->id,
            productId: $record->ownerId,
            languageCode: $record->languageCode,
            name: $record->fields->name,
            shortDescription: $record->fields->shortDescription,
            description: $record->fields->description,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
            deletedAt: $record->deletedAt,
        );
    }

    public static function toOptionContent(ContentRecordDTO $record): OptionContentDTO
    {
        return new OptionContentDTO(
            id: $record->id,
            optionId: $record->ownerId,
            languageCode: $record->languageCode,
            name: $record->fields->name,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
            deletedAt: $record->deletedAt,
        );
    }

    public static function toOptionValueContent(ContentRecordDTO $record): OptionValueContentDTO
    {
        return new OptionValueContentDTO(
            id: $record->id,
            optionValueId: $record->ownerId,
            languageCode: $record->languageCode,
            name: $record->fields->name,
            createdAt: $record->createdAt,
            updatedAt: $record->updatedAt,
            deletedAt: $record->deletedAt,
        );
    }
}
