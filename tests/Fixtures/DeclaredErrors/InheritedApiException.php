<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

/** Declares nothing itself: the name comes from {@see ApiException}, whose file is a cache dependency. */
final class InheritedApiException extends ApiException {}
