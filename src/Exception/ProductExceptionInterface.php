<?php

declare(strict_types=1);

namespace Maatify\Product\Exception;

use Throwable;

/**
 * Marker implemented by every exception that this package defines.
 *
 * Infrastructure failures that the package intentionally lets propagate, such as
 * an unclassified {@see \PDOException}, do not implement it.
 */
interface ProductExceptionInterface extends Throwable {}
