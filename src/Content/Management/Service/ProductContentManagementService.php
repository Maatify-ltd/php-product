<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\CreatedProductContentDTO;
use Maatify\Product\Content\DTO\ProductContentCollectionDTO;
use Maatify\Product\Content\DTO\ProductContentDTO;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Command\UpdateProductContentCommand;
use Maatify\Product\Content\Management\Criteria\ProductContentListCriteriaDTO;
use Maatify\Product\Content\Mapper\ContentRecordMapper;
use Maatify\Product\Content\Service\ContentLifecycle;

/**
 * Product Content management service: delegates to the shared {@see ContentLifecycle} and maps its records.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ProductContentManagementService implements ProductContentManagementServiceInterface
{
    public function __construct(private ContentLifecycle $content) {}

    public function create(CreateProductContentCommand $command): CreatedProductContentDTO
    {
        return new CreatedProductContentDTO($this->content->create(
            $command->productId,
            $command->languageCode,
            new ContentFieldsDTO($command->name, $command->shortDescription, $command->description),
        ));
    }

    public function update(UpdateProductContentCommand $command): void
    {
        $this->content->update($command->contentId, new ContentFieldsDTO($command->name, $command->shortDescription, $command->description));
    }

    public function softDelete(int $contentId): void
    {
        $this->content->softDelete($contentId);
    }

    public function restore(int $contentId): void
    {
        $this->content->restore($contentId);
    }

    public function getByIdForManagement(
        int $contentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductContentDTO {
        return ContentRecordMapper::toProductContent($this->content->getByIdForManagement($contentId, $deletedState));
    }

    public function listForManagement(ProductContentListCriteriaDTO $criteria): ProductContentCollectionDTO
    {
        return new ProductContentCollectionDTO(array_map(
            ContentRecordMapper::toProductContent(...),
            $this->content->listForManagement($criteria->productId, $criteria->language, $criteria->deletedState, $criteria->maxResults),
        ));
    }

    public function paginateForManagement(ProductContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->content->paginateForManagement($criteria->productId, $criteria->language, $criteria->deletedState, $request, ContentRecordMapper::toProductContent(...));
    }
}
