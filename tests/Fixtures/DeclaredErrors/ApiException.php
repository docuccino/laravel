<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A base every API error extends, naming the component on behalf of the subclasses that don't. */
#[ErrorComponent('ApiFailure')]
abstract class ApiException extends RuntimeException {}
