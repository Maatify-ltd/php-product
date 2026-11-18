<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository\Pdo;

/**
 * Product-scoped visibility relations for the Variant, Option, and Option Value read adapters.
 *
 * @internal Not part of the Public Runtime API.
 */
final class VariantVisibilitySql
{
    private function __construct() {}

    /** Seed the visibility relation from a Product identity. */
    public static function forProduct(): string
    {
        return self::visibility('SELECT id AS product_id, id AS subject_id FROM maa_product_products WHERE id = :product_id');
    }

    /** Seed the visibility relation from a Variant identity. */
    public static function forVariant(): string
    {
        return self::visibility('SELECT product_id, id AS subject_id FROM maa_product_variants WHERE id = :variant_id');
    }

    /** Seed the visibility relation from an Option identity. */
    public static function forOption(): string
    {
        return self::visibility('SELECT product_id, id AS subject_id FROM maa_product_options WHERE id = :option_id');
    }

    /**
     * The distinct relations prevent MySQL from merging the Product-wide coverage check into the
     * per-Variant read. Each relation is bounded by the one Product in the supplied scope query.
     */
    private static function visibility(string $scope): string
    {
        return 'WITH visibility_scope AS (' . $scope . '), '
            . 'valid_variants AS (SELECT DISTINCT candidate.id, candidate.product_id '
            . 'FROM visibility_scope scope JOIN maa_product_variants candidate ON candidate.product_id = scope.product_id '
            . 'WHERE candidate.deleted_at IS NULL AND candidate.status = \'active\' AND ' . self::validCompositionPredicate('candidate', 'a') . '), '
            . 'represented_values AS (SELECT DISTINCT pair.option_value_id FROM valid_variants variant '
            . 'STRAIGHT_JOIN maa_product_variant_option_values pair FORCE INDEX (uq_maa_product_variant_option_values_variant_option) '
            . 'ON pair.variant_id = variant.id), '
            . 'eligible_product AS (SELECT DISTINCT product.id FROM visibility_scope scope '
            . 'JOIN maa_product_products product ON product.id = scope.product_id '
            . 'WHERE product.deleted_at IS NULL AND product.status = \'active\' '
            . 'AND NOT EXISTS (SELECT 1 FROM maa_product_options opt '
            . 'JOIN maa_product_option_values val ON val.option_id = opt.id '
            . 'WHERE opt.product_id = product.id AND opt.deleted_at IS NULL AND opt.status = \'active\' '
            . 'AND val.deleted_at IS NULL AND val.status = \'active\' '
            . 'AND NOT EXISTS (SELECT 1 FROM represented_values covered WHERE covered.option_value_id = val.id))), '
            . 'visible_variants AS (SELECT variant.id FROM valid_variants variant '
            . 'JOIN eligible_product product ON product.id = variant.product_id) ';
    }

    /**
     * Structural validity of the composition of the Variant aliased `$v`: every selected Option
     * and Value is active, not deleted, and labelled, and the composition covers exactly the
     * Product's active Options. A pair per Option is unique, so equal counts over active
     * Options mean exact coverage. `$s` makes the inner aliases unique per nesting level.
     */
    private static function validCompositionPredicate(string $v, string $s): string
    {
        return 'NOT EXISTS (SELECT 1 FROM `maa_product_variant_option_values` cv' . $s . ' '
            . 'JOIN `maa_product_options` o' . $s . ' ON o' . $s . '.`id` = cv' . $s . '.`option_id` '
            . 'JOIN `maa_product_option_values` ov' . $s . ' ON ov' . $s . '.`id` = cv' . $s . '.`option_value_id` WHERE '
            . 'cv' . $s . '.`variant_id` = ' . $v . '.`id` AND ('
            . 'o' . $s . '.`deleted_at` IS NOT NULL OR o' . $s . '.`status` <> \'active\' '
            . 'OR ov' . $s . '.`deleted_at` IS NOT NULL OR ov' . $s . '.`status` <> \'active\' '
            . 'OR NOT ' . self::labelled('maa_product_option_contents', 'option_id', 'o' . $s) . ' '
            . 'OR NOT ' . self::labelled('maa_product_option_value_contents', 'option_value_id', 'ov' . $s) . ')) '
            . 'AND (SELECT COUNT(*) FROM `maa_product_variant_option_values` cp' . $s . ' WHERE cp' . $s . '.`variant_id` = ' . $v . '.`id`) = '
            . '(SELECT COUNT(*) FROM `maa_product_options` ao' . $s . ' WHERE ao' . $s . '.`product_id` = ' . $v . '.`product_id` '
            . 'AND ao' . $s . '.`deleted_at` IS NULL AND ao' . $s . '.`status` = \'active\')';
    }

    /**
     * `EXISTS` predicate: the owner aliased `$alias` has a non-deleted Content record with a non-empty label.
     */
    public static function labelled(string $table, string $key, string $alias): string
    {
        return 'EXISTS (SELECT 1 FROM `' . $table . '` c_' . $alias . ' WHERE c_' . $alias . '.`' . $key . '` = ' . $alias . '.`id` '
            . 'AND c_' . $alias . '.`deleted_at` IS NULL AND c_' . $alias . '.`name` REGEXP \'[^[:space:]]\')';
    }
}
