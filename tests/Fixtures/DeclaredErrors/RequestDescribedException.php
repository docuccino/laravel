<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A description of a request body, on a class that is an error: the operation-level form, misplaced. */
#[ErrorComponent('RequestDescribed')]
#[Description(text: 'Send the identifier in the path.', request: true)]
final class RequestDescribedException extends RuntimeException {}
