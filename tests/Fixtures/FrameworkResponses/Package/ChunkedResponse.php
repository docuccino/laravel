<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** A package's streamed response, whose callback writes the body. */
class ChunkedResponse extends StreamedResponse {}
