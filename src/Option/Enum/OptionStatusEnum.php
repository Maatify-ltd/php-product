<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Enum;

/**
 * Lifecycle status of an Option. The backing values are exactly the lowercase strings the
 * database accepts; the status is independent of soft deletion.
 */
enum OptionStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
