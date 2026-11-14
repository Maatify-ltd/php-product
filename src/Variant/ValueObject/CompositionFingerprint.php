<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\ValueObject;

/**
 * Canonical composition fingerprint of a Variant, as defined by the V1 architecture.
 *
 * The fingerprint is the lowercase hexadecimal SHA-256 digest of the canonical
 * composition string: `v1` for the empty composition, followed by one
 * `|{option_id}={option_value_id}` segment per pair, the pairs sorted by numeric
 * Option identity. The fingerprint is an internal enforcement key; a stored match must
 * still be confirmed against the actual pairs before two compositions are treated as equal.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class CompositionFingerprint
{
    /** Canonical string of the empty composition. */
    private const string EMPTY_CANONICAL = 'v1';

    /**
     * @param non-empty-string $canonical The canonical composition string the digest was computed from.
     * @param non-empty-string $value Lowercase 64-character hexadecimal digest.
     */
    private function __construct(
        public string $canonical,
        public string $value,
    ) {}

    /**
     * The fingerprint of a Variant with no Option Values, the identity of a simple Product's Variant.
     */
    public static function forEmptyComposition(): self
    {
        return self::forSelections([]);
    }

    /**
     * The fingerprint of a composition given in any order; the pairs are canonicalized first.
     *
     * @param list<OptionValueSelection> $selections Must not repeat an Option.
     */
    public static function forSelections(array $selections): self
    {
        $canonical = self::EMPTY_CANONICAL;
        foreach (OptionValueSelection::canonicalize($selections) as $selection) {
            $canonical .= '|' . $selection->optionId . '=' . $selection->optionValueId;
        }

        return new self($canonical, hash('sha256', $canonical));
    }
}
