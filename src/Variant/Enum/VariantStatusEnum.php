<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Enum;

/**
 * Lifecycle status of a Variant.
 *
 * The backing values are exactly the lowercase strings the database accepts. The
 * status is independent of soft deletion.
 */
enum VariantStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
