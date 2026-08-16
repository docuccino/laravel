<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** An illegal name carrying a control character, which the diagnostic quoting it has to escape. */
#[ErrorComponent("Not\x1B[31mFound")]
final class EscapedNameException extends RuntimeException {}
