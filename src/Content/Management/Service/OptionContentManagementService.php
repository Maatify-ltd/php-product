<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\CreatedOptionContentDTO;
use Maatify\Product\Content\DTO\OptionContentCollectionDTO;
use Maatify\Product\Content\DTO\OptionContentDTO;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionContentCommand;
use Maatify\Product\Content\Management\Criteria\OptionContentListCriteriaDTO;
use Maatify\Product\Content\Mapper\ContentRecordMapper;
use Maatify\Product\Content\Service\ContentLifecycle;

/**
 * Option Content management service: delegates to the shared {@see ContentLifecycle} and maps its records.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionContentManagementService implements OptionContentManagementServiceInterface
{
    public function __construct(private ContentLifecycle $content) {}

    public function create(CreateOptionContentCommand $command): CreatedOptionContentDTO
    {
        return new CreatedOptionContentDTO($this->content->create(
            $command->optionId,
            $command->languageCode,
            new ContentFieldsDTO($command->name),
        ));
    }

    public function update(UpdateOptionContentCommand $command): void
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
    ): OptionContentDTO {
        return ContentRecordMapper::toOptionContent($this->content->getByIdForManagement($contentId, $deletedState));
    }

    public function listForManagement(OptionContentListCriteriaDTO $criteria): OptionContentCollectionDTO
    {
        return new OptionContentCollectionDTO(array_map(
            ContentRecordMapper::toOptionContent(...),
            $this->content->listForManagement($criteria->optionId, $criteria->language, $criteria->deletedState, $criteria->maxResults),
        ));
    }

    public function paginateForManagement(OptionContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->content->paginateForManagement($criteria->optionId, $criteria->language, $criteria->deletedState, $request, ContentRecordMapper::toOptionContent(...));
    }
}
