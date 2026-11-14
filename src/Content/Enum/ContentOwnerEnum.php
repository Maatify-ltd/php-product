<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Enum;

use Maatify\Product\Content\Exception\OptionContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionContentNotFoundException;
use Maatify\Product\Content\Exception\OptionValueContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionValueContentNotFoundException;
use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;
use Maatify\Product\Content\Exception\ProductContentNotFoundException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Throwable;

/**
 * The three owners of Content records and the trusted table metadata and named failures of each.
 * Every identifier returned here is a package constant, never caller input.
 *
 * @internal Not part of the Public Runtime API.
 */
enum ContentOwnerEnum: string
{
    case PRODUCT = 'product';
    case OPTION = 'option';
    case OPTION_VALUE = 'option_value';

    public function table(): string
    {
        return match ($this) {
            self::PRODUCT => 'maa_product_product_contents',
            self::OPTION => 'maa_product_option_contents',
            self::OPTION_VALUE => 'maa_product_option_value_contents',
        };
    }

    public function ownerColumn(): string
    {
        return match ($this) {
            self::PRODUCT => 'product_id',
            self::OPTION => 'option_id',
            self::OPTION_VALUE => 'option_value_id',
        };
    }

    /**
     * The name of the unique key on `(owner, language_code_identity)`.
     */
    public function identityKey(): string
    {
        return 'uq_' . $this->table() . '_identity';
    }

    /**
     * Whether the records carry the optional short description and description (Product Content only).
     */
    public function hasDescriptions(): bool
    {
        return $this === self::PRODUCT;
    }

    /**
     * The resource name used in failure messages.
     */
    public function resource(): string
    {
        return match ($this) {
            self::PRODUCT => 'Product Content',
            self::OPTION => 'Option Content',
            self::OPTION_VALUE => 'Option Value Content',
        };
    }

    /**
     * The owner name used in failure messages.
     */
    public function ownerResource(): string
    {
        return match ($this) {
            self::PRODUCT => 'Product',
            self::OPTION => 'Option',
            self::OPTION_VALUE => 'Option Value',
        };
    }

    public function contentNotFound(int $contentId): Throwable&ProductExceptionInterface
    {
        return match ($this) {
            self::PRODUCT => ProductContentNotFoundException::withId($contentId),
            self::OPTION => OptionContentNotFoundException::withId($contentId),
            self::OPTION_VALUE => OptionValueContentNotFoundException::withId($contentId),
        };
    }

    public function ownerNotFound(int $ownerId): Throwable&ProductExceptionInterface
    {
        return match ($this) {
            self::PRODUCT => ProductNotFoundException::withId($ownerId),
            self::OPTION => OptionNotFoundException::withId($ownerId),
            self::OPTION_VALUE => OptionValueNotFoundException::withId($ownerId),
        };
    }

    public function identityTaken(int $ownerId, ?string $languageCode, Throwable $previous): Throwable&ProductExceptionInterface
    {
        return match ($this) {
            self::PRODUCT => ProductContentAlreadyExistsException::forIdentity($ownerId, $languageCode, $previous),
            self::OPTION => OptionContentAlreadyExistsException::forIdentity($ownerId, $languageCode, $previous),
            self::OPTION_VALUE => OptionValueContentAlreadyExistsException::forIdentity($ownerId, $languageCode, $previous),
        };
    }
}
