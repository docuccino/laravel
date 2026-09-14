<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** A base naming and describing one error on behalf of every subclass that declares nothing itself. */
#[ErrorComponent('DescribedFailure')]
#[Description(text: 'The request could not be completed.')]
abstract class DescribedApiException extends RuntimeException {}
