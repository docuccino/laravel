<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/**
 * The one-character typo: an argument the attribute's constructor cannot take. Constructing it throws a
 * `TypeError` that names the absolute path of the file it was written in, so the reader must never build
 * it — a route that collapsed on this would print this machine's paths into the emitted document.
 */
#[ErrorComponent(5)]
final class MistypedNameException extends RuntimeException {}
