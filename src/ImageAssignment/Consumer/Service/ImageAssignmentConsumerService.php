<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Consumer\Service;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentCollectionDTO;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentQueryReaderInterface;

/**
 * Serves the consumer read of Image Assignments. Reads write nothing and open no transaction.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ImageAssignmentConsumerService implements ImageAssignmentConsumerServiceInterface
{
    public function __construct(private ImageAssignmentQueryReaderInterface $reader) {}

    public function listVisibleByExactScope(ImageAssignmentExactScopeCriteriaDTO $criteria): ImageAssignmentCollectionDTO
    {
        return new ImageAssignmentCollectionDTO($this->reader->listVisibleByExactScope($criteria));
    }

    public function paginateVisibleByExactScope(ImageAssignmentExactScopeCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->reader->paginateVisibleByExactScope($criteria, $request);
    }
}
