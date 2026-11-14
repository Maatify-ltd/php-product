<?php

declare(strict_types=1);

namespace Maatify\Product\Product\DTO;

use JsonSerializable;
use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\OptionValue\DTO\OptionValueStateDTO;
use Maatify\Product\Variant\DTO\VariantStateDTO;

/**
 * The complete configuration graph of one Product as read under the Product lock: the
 * Product's lifecycle state, every Option, every Option Value, and every Variant with its
 * composition, including inactive and soft-deleted rows.
 *
 * It is immutable; the `with*` methods return the graph as it would be after a mutation so
 * the invariants can be validated before anything is written.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ProductConfigurationSnapshotDTO implements JsonSerializable
{
    /**
     * @param bool $hasName Whether a non-deleted Product Content record with a non-empty name exists.
     * @param array<int, OptionStateDTO> $options Keyed by Option identity.
     * @param array<int, OptionValueStateDTO> $optionValues Keyed by Option Value identity.
     * @param array<int, VariantStateDTO> $variants Keyed by Variant identity.
     */
    public function __construct(
        public int $productId,
        public bool $productActive,
        public bool $productDeleted,
        public bool $hasName,
        public array $options,
        public array $optionValues,
        public array $variants,
        public int $configurationRevision = 1,
    ) {}

    public function withProduct(bool $active, bool $deleted): self
    {
        return new self($this->productId, $active, $deleted, $this->hasName, $this->options, $this->optionValues, $this->variants, $this->configurationRevision);
    }

    /**
     * The graph with the Product's name state replaced, as after a Product Content change.
     */
    public function withHasName(bool $hasName): self
    {
        return new self($this->productId, $this->productActive, $this->productDeleted, $hasName, $this->options, $this->optionValues, $this->variants, $this->configurationRevision);
    }

    public function withOption(OptionStateDTO $option): self
    {
        $options = $this->options;
        $options[$option->id] = $option;

        return new self($this->productId, $this->productActive, $this->productDeleted, $this->hasName, $options, $this->optionValues, $this->variants, $this->configurationRevision);
    }

    public function withOptionValue(OptionValueStateDTO $value): self
    {
        $values = $this->optionValues;
        $values[$value->id] = $value;

        return new self($this->productId, $this->productActive, $this->productDeleted, $this->hasName, $this->options, $values, $this->variants, $this->configurationRevision);
    }

    public function withVariant(VariantStateDTO $variant): self
    {
        $variants = $this->variants;
        $variants[$variant->id] = $variant;

        return new self($this->productId, $this->productActive, $this->productDeleted, $this->hasName, $this->options, $this->optionValues, $variants, $this->configurationRevision);
    }

    /** @return array{productId: int, productActive: bool, productDeleted: bool, hasName: bool, options: array<int, array{id: int, productId: int, active: bool, deleted: bool, labelled: bool}>, optionValues: array<int, array{id: int, optionId: int, active: bool, deleted: bool, labelled: bool}>, variants: array<int, array{id: int, productId: int, fingerprint: string, active: bool, deleted: bool, composition: list<array{optionId: int, optionValueId: int}>}>} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'productActive' => $this->productActive,
            'productDeleted' => $this->productDeleted,
            'hasName' => $this->hasName,
            'options' => array_map(static fn(OptionStateDTO $option): array => $option->jsonSerialize(), $this->options),
            'optionValues' => array_map(static fn(OptionValueStateDTO $value): array => $value->jsonSerialize(), $this->optionValues),
            'variants' => array_map(static fn(VariantStateDTO $variant): array => $variant->jsonSerialize(), $this->variants),
        ];
    }
}
