<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Enum;

/**
 * Lifecycle status of an Option Value. The backing values are exactly the lowercase strings the
 * database accepts; the status is independent of soft deletion.
 */
enum OptionValueStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
