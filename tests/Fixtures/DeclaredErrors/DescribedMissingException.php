<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** The simple case: one class naming the error it stands for and saying what it is. */
#[ErrorComponent('ResourceMissing')]
#[Description(text: 'No record matches the identifier in the path.')]
final class DescribedMissingException extends RuntimeException {}
