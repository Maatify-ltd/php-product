<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Public package failure: raised when storage returns data that does not match the package
 * schema contract (see the Package Reference failure contract).
 *
 * Consumers catch it by class or through {@see ProductExceptionInterface}. The class exposes
 * no named constructors of its own: the package raises it through an internal factory, so the
 * type and its marker are the whole consumer-facing contract.
 *
 * Unclassified database failures propagate unchanged as {@see \PDOException}.
 */
final class ProductPersistenceException extends SystemMaatifyException implements ProductExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeEnum
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
