<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository\Pdo;

/**
 * The single source of the consumer-visibility SQL of a Variant, shared by the Variant reads and
 * by the consumer reads of Options and Option Values that depend on it, including the product-wide unrepresented-Value rule. Every alias it
 * receives is a trusted package constant, never caller input; the subqueries it adds use their own
 * aliases, so it can be embedded in any query that names the Variant row.
 *
 * @internal Not part of the Public Runtime API.
 */
final class VariantVisibilitySql
{
    private function __construct() {}

    /**
     * Consumer visibility of the Variant aliased `$v`: its Product and the Variant itself are
     * active and not deleted, its own composition is valid, and the Product's configuration is
     * valid as a whole: no active Value of an active Option is left unrepresented by an active,
     * valid Variant. The product-wide rule fails closed, so a broken configuration hides every
     * Variant of the Product from the single get and from the list alike.
     */
    public static function visiblePredicate(string $v): string
    {
        return $v . '.`deleted_at` IS NULL AND ' . $v . '.`status` = \'active\' '
            . 'AND EXISTS (SELECT 1 FROM `maa_product_products` p WHERE p.`id` = ' . $v . '.`product_id` '
            . 'AND p.`deleted_at` IS NULL AND p.`status` = \'active\') '
            . 'AND ' . self::validCompositionPredicate($v, 'a')
            . ' AND NOT EXISTS (SELECT 1 FROM `maa_product_option_values` uv '
            . 'JOIN `maa_product_options` uo ON uo.`id` = uv.`option_id` WHERE '
            . 'uo.`product_id` = ' . $v . '.`product_id` AND uo.`deleted_at` IS NULL AND uo.`status` = \'active\' '
            . 'AND uv.`deleted_at` IS NULL AND uv.`status` = \'active\' '
            . 'AND NOT EXISTS (SELECT 1 FROM `maa_product_variants` rv '
            . 'JOIN `maa_product_variant_option_values` rp ON rp.`variant_id` = rv.`id` WHERE '
            . 'rp.`option_value_id` = uv.`id` AND rv.`product_id` = ' . $v . '.`product_id` '
            . 'AND rv.`deleted_at` IS NULL AND rv.`status` = \'active\' '
            . 'AND ' . self::validCompositionPredicate('rv', 'b') . '))';
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
