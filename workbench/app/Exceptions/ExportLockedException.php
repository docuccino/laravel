<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A named exception over the framework's own constructor: it adds no constructor of its own, so the status
 * is still argument 0 and each `throw new ExportLockedException(423, …)` says which one it is.
 */
final class ExportLockedException extends HttpException {}
