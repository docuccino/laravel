<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A second exception claiming the same name; rendered under the same status it is the same error. */
#[ErrorComponent('ResourceMissing')]
final class OtherThingMissingException extends RuntimeException {}
