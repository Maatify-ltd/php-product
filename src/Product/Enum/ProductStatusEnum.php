<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Enum;

/**
 * Lifecycle status of a Product.
 *
 * The backing values are exactly the lowercase strings the database accepts. The
 * status is independent of soft deletion.
 */
enum ProductStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
