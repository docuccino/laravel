<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A declaration carrying both `text:` and `file:`, which says two things and settles neither. */
#[ErrorComponent('DoublyDescribed')]
#[Description(text: 'Written inline.', file: 'errors/doubly-described.md')]
final class DoublyDescribedException extends RuntimeException {}
