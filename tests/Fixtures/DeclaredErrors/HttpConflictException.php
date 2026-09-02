<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An HTTP exception carrying a status of its own, marked so the error it publishes is named rather than keyed. */
#[ErrorComponent('ResourceMissing')]
final class HttpConflictException extends ConflictHttpException {}
