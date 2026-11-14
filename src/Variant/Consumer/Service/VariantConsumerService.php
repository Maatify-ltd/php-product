<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Consumer\Service;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Repository\VariantQueryReaderInterface;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;

/**
 * Serves the consumer reads and composition resolution of Variants. Reads write nothing and open
 * no transaction.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class VariantConsumerService implements VariantConsumerServiceInterface
{
    public function __construct(private VariantQueryReaderInterface $reader) {}

    public function getById(int $variantId): VariantDTO
    {
        self::requirePositive($variantId, 'variantId');
        $variant = $this->reader->findVisibleById($variantId);
        if ($variant === null) {
            throw VariantNotFoundException::withId($variantId);
        }

        return $variant;
    }

    public function listByProductId(
        int $productId,
        VariantVisibleListCriteriaDTO $criteria = new VariantVisibleListCriteriaDTO(),
    ): VariantCollectionDTO {
        self::requirePositive($productId, 'productId');

        return new VariantCollectionDTO($this->reader->listVisible($productId, $criteria));
    }

    public function resolveByComposition(int $productId, VariantCompositionCriteriaDTO $criteria): VariantDTO
    {
        self::requirePositive($productId, 'productId');
        if (!$this->reader->isProductVisible($productId)) {
            throw ProductNotFoundException::withId($productId);
        }

        $activeOptions = array_fill_keys($this->reader->listActiveOptionIds($productId), true);
        $valueOwners = $this->reader->findActiveValueOptionIds(
            $productId,
            array_map(static fn(OptionValueSelection $selection): int => $selection->optionValueId, $criteria->selections),
        );

        foreach ($criteria->selections as $selection) {
            if (!isset($activeOptions[$selection->optionId])) {
                throw ProductInvalidArgumentException::optionNotSelectable($selection->optionId, $productId);
            }
            if (($valueOwners[$selection->optionValueId] ?? null) !== $selection->optionId) {
                throw ProductInvalidArgumentException::optionValueNotSelectable($selection->optionValueId, $selection->optionId);
            }
            unset($activeOptions[$selection->optionId]);
        }
        $missing = array_keys($activeOptions);
        if ($missing !== []) {
            throw ProductInvalidArgumentException::incompleteSelection($productId, $missing);
        }

        $fingerprint = CompositionFingerprint::forSelections($criteria->selections);
        $match = $this->reader->findActiveByFingerprint($productId, $fingerprint->value);
        if ($match === null) {
            throw VariantNotFoundException::forComposition($productId, $fingerprint->canonical);
        }
        if (CompositionFingerprint::forSelections($match->composition)->canonical !== $fingerprint->canonical) {
            throw ProductStorageFailureFactory::compositionFingerprintMismatch($match->id);
        }
        $visible = $this->reader->findVisibleById($match->id);
        if ($visible === null) {
            throw VariantNotFoundException::forComposition($productId, $fingerprint->canonical);
        }

        return $visible;
    }

    private static function requirePositive(int $identity, string $field): void
    {
        if ($identity < 1) {
            throw ProductInvalidArgumentException::nonPositiveId($field);
        }
    }

    public function paginateByProductId(int $productId, PageRequest $request): PageResultDTO
    {
        self::requirePositive($productId, 'productId');

        return $this->reader->paginateVisible($productId, $request);
    }
}
