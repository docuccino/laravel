<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A description in a form a schema cannot hold: nothing resolves an application-root path here. */
#[ErrorComponent('FileDescribed')]
#[Description(file: 'errors/file-described.md')]
final class FileDescribedException extends RuntimeException {}
