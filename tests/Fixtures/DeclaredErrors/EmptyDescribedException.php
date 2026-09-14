<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A declaration carrying neither `text:` nor `file:`, which says nothing certain. */
#[ErrorComponent('EmptyDescribed')]
#[Description]
final class EmptyDescribedException extends RuntimeException {}
