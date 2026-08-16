<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An HTTP exception, so the Problem Details preset answers for it with a reference to its own component. */
#[ErrorComponent('ResourceMissing')]
final class HttpConflictException extends ConflictHttpException {}
