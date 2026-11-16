<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use RuntimeException;

/** An order that changed while it was being edited. */
class OrderConflictException extends RuntimeException {}
