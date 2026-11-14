<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Consumer\Service;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Option\Consumer\Criteria\OptionVisibleListCriteriaDTO;
use Maatify\Product\Option\DTO\OptionCollectionDTO;
use Maatify\Product\Option\Repository\OptionQueryReaderInterface;

/**
 * Serves the consumer read of Options. Reads write nothing and open no transaction.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionConsumerService implements OptionConsumerServiceInterface
{
    public function __construct(private OptionQueryReaderInterface $reader) {}

    public function listVisibleByProductId(
        int $productId,
        OptionVisibleListCriteriaDTO $criteria = new OptionVisibleListCriteriaDTO(),
    ): OptionCollectionDTO {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }

        return new OptionCollectionDTO($this->reader->listVisible($productId, $criteria->maxResults));
    }

    public function paginateVisibleByProductId(int $productId, PageRequest $request): PageResultDTO
    {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }

        return $this->reader->paginateVisible($productId, $request);
    }
}
