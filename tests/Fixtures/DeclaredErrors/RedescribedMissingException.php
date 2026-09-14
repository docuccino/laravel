<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A second class claiming that name and describing it differently — the sentence neither of them keeps. */
#[ErrorComponent('ResourceMissing')]
#[Description(text: 'The record was removed and will not come back.')]
final class RedescribedMissingException extends RuntimeException {}
