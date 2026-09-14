<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;

/**
 * Names an error of its own and says nothing about it. The base's sentence describes the base's error,
 * so it does not travel to this one.
 */
#[ErrorComponent('RenamedFailure')]
final class RenamedDescribedException extends DescribedApiException {}
