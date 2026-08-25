<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/**
 * A class-anchored declaration for the reported topology: one 422 answered with two representations
 * beside others answered with one, all of them named by this class.
 */
#[ErrorComponent('ValidationError')]
final class ValidationFailedException extends RuntimeException {}
