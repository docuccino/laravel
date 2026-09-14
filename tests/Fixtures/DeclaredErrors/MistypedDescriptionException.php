<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/**
 * The same typo one attribute over: an argument `#[Description]`'s constructor cannot take. Reading the
 * sentence has to be as total as reading the name, or the `TypeError` prints this machine's absolute
 * paths into the emitted document.
 */
#[ErrorComponent('MistypedDescription')]
#[Description(5)]
final class MistypedDescriptionException extends RuntimeException {}
