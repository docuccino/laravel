<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A name no OpenAPI component key can carry, so the build refuses it and says so. */
#[ErrorComponent('Not Found!')]
final class MalformedNameException extends RuntimeException {}
