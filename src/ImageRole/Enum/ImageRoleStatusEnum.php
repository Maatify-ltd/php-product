<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Enum;

/**
 * Lifecycle status of a Product Image Role. The backing values are exactly the lowercase strings
 * the database accepts; the status is independent of soft deletion.
 */
enum ImageRoleStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
