<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when a caller supplies an argument that violates the package input contract.
 */
final class ProductInvalidArgumentException extends InvalidArgumentMaatifyException implements ProductExceptionInterface
{
    /**
     * The field is empty or contains only whitespace.
     */
    public static function emptyField(string $field): self
    {
        return new self(sprintf('Field "%s" must not be empty or whitespace only.', $field));
    }

    /**
     * The field is longer than its documented maximum number of characters.
     */
    public static function fieldTooLong(string $field, int $maxLength): self
    {
        return new self(sprintf('Field "%s" must not exceed %d characters.', $field, $maxLength));
    }

    /**
     * The field is longer than its documented maximum number of bytes.
     */
    public static function fieldTooLongInBytes(string $field, int $maxBytes): self
    {
        return new self(sprintf('Field "%s" must not exceed %d bytes.', $field, $maxBytes));
    }

    /**
     * The field is not valid UTF-8 text.
     */
    public static function invalidEncoding(string $field): self
    {
        return new self(sprintf('Field "%s" must be valid UTF-8 text.', $field));
    }

    /**
     * The field is not a positive integer.
     */
    public static function nonPositiveId(string $field): self
    {
        return new self(sprintf('Field "%s" must be a positive integer.', $field));
    }

    /**
     * The field is not a canonical positive integer representation.
     */
    public static function invalidIdentityRepresentation(string $field): self
    {
        return new self(sprintf(
            'Field "%s" must be a positive integer, or a string of digits without sign, whitespace, or leading zeroes, not above PHP_INT_MAX.',
            $field,
        ));
    }

    /**
     * A display order is not a positive integer.
     */
    public static function invalidDisplayOrder(int $displayOrder): self
    {
        return new self(sprintf('Display order must be a positive integer, got %d.', $displayOrder));
    }

    /**
     * A list limit is outside the allowed range.
     */
    public static function invalidListLimit(int $limit, int $maximum): self
    {
        return new self(sprintf('List maxResults must be between 1 and %d, got %d.', $maximum, $limit));
    }

    /**
     * A collection that must not be empty is empty.
     */
    public static function emptyCollection(string $field): self
    {
        return new self(sprintf('Field "%s" must contain at least one element.', $field));
    }

    /**
     * The same identity appears more than once where it must be unique.
     */
    public static function duplicateIdentity(string $field, int $id): self
    {
        return new self(sprintf('Field "%s" names identity [%d] more than once.', $field, $id));
    }

    /**
     * The same composition appears more than once in one request.
     */
    public static function duplicateComposition(string $canonical): self
    {
        return new self(sprintf('The composition "%s" appears more than once in the request.', $canonical));
    }

    /**
     * A selection names an Option that is not an active Option of the Product.
     */
    public static function optionNotSelectable(int $optionId, int $productId): self
    {
        return new self(sprintf(
            'Option [%d] is not an active, non-deleted Option of Product [%d].',
            $optionId,
            $productId,
        ));
    }

    /**
     * A selection names a Value that is not an active Value of the selected Option.
     */
    public static function optionValueNotSelectable(int $optionValueId, int $optionId): self
    {
        return new self(sprintf(
            'Option Value [%d] is not an active, non-deleted Value of Option [%d].',
            $optionValueId,
            $optionId,
        ));
    }

    /**
     * A selection does not cover every active Option of the Product.
     *
     * @param list<int> $missingOptionIds
     */
    public static function incompleteSelection(int $productId, array $missingOptionIds): self
    {
        return new self(sprintf(
            'The selection for Product [%d] does not cover its active Options: missing Option identities [%s].',
            $productId,
            implode(', ', $missingOptionIds),
        ));
    }

    /**
     * A Variant does not belong to the Product named by the request.
     */
    public static function variantNotOfProduct(int $variantId, int $productId): self
    {
        return new self(sprintf('Variant [%d] does not belong to Product [%d].', $variantId, $productId));
    }

    /**
     * The PDO connection does not report errors as exceptions.
     */
    public static function pdoMustThrowExceptions(): self
    {
        return new self('The PDO connection must use PDO::ERRMODE_EXCEPTION.');
    }

    /** The lookup needs a distinct, idle autocommit connection that reports errors. */
    public static function invalidOwnerLookupConnection(): self
    {
        return new self('Owner lookup requires a separate autocommit PDO with exception mode and no active transaction.');
    }
}
