<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Enum;

/** Explicit soft-deletion visibility for management reads. */
enum ProductDeletedStateEnum: string
{
    case NON_DELETED = 'non_deleted';
    case INCLUDE_DELETED = 'include_deleted';
    case DELETED_ONLY = 'deleted_only';
}
