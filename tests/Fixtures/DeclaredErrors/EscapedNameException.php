<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** An illegal name carrying a control character, quoted as written by the diagnostic that refuses it. */
#[ErrorComponent("Not\x1B[31mFound")]
final class EscapedNameException extends RuntimeException {}
