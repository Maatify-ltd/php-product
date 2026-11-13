<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\CreatedOptionValueContentDTO;
use Maatify\Product\Content\DTO\OptionValueContentCollectionDTO;
use Maatify\Product\Content\DTO\OptionValueContentDTO;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionValueContentCommand;
use Maatify\Product\Content\Management\Criteria\OptionValueContentListCriteriaDTO;
use Maatify\Product\Content\Mapper\ContentRecordMapper;
use Maatify\Product\Content\Service\ContentLifecycle;

/**
 * Option Value Content management service: delegates to the shared {@see ContentLifecycle} and maps its records.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionValueContentManagementService implements OptionValueContentManagementServiceInterface
{
    public function __construct(private ContentLifecycle $content) {}

    public function create(CreateOptionValueContentCommand $command): CreatedOptionValueContentDTO
    {
        return new CreatedOptionValueContentDTO($this->content->create(
            $command->optionValueId,
            $command->languageCode,
            new ContentFieldsDTO($command->name),
        ));
    }

    public function update(UpdateOptionValueContentCommand $command): void
    {
        $this->content->update($command->contentId, new ContentFieldsDTO($command->name));
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
    ): OptionValueContentDTO {
        return ContentRecordMapper::toOptionValueContent($this->content->getByIdForManagement($contentId, $deletedState));
    }

    public function listForManagement(OptionValueContentListCriteriaDTO $criteria): OptionValueContentCollectionDTO
    {
        return new OptionValueContentCollectionDTO(array_map(
            ContentRecordMapper::toOptionValueContent(...),
            $this->content->listForManagement($criteria->optionValueId, $criteria->language, $criteria->deletedState, $criteria->maxResults),
        ));
    }

    public function paginateForManagement(OptionValueContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->content->paginateForManagement($criteria->optionValueId, $criteria->language, $criteria->deletedState, $request, ContentRecordMapper::toOptionValueContent(...));
    }
}
