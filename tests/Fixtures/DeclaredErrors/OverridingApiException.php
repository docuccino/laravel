<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;

/** Names itself, so the nearest declaration wins over the base's. */
#[ErrorComponent('PolicyRefused')]
final class OverridingApiException extends ApiException {}
