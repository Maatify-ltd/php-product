<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Consumer\Service;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\OptionValue\Consumer\Criteria\OptionValueVisibleListCriteriaDTO;
use Maatify\Product\OptionValue\DTO\OptionValueCollectionDTO;
use Maatify\Product\OptionValue\Repository\OptionValueQueryReaderInterface;

/**
 * Serves the consumer read of Option Values. Reads write nothing and open no transaction.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionValueConsumerService implements OptionValueConsumerServiceInterface
{
    public function __construct(private OptionValueQueryReaderInterface $reader) {}

    public function listVisibleByOptionId(
        int $optionId,
        OptionValueVisibleListCriteriaDTO $criteria = new OptionValueVisibleListCriteriaDTO(),
    ): OptionValueCollectionDTO {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }

        return new OptionValueCollectionDTO($this->reader->listVisible($optionId, $criteria->maxResults));
    }

    public function paginateVisibleByOptionId(int $optionId, PageRequest $request): PageResultDTO
    {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }

        return $this->reader->paginateVisible($optionId, $request);
    }
}
