<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses;

use Illuminate\Http\JsonResponse;

/**
 * An app's own response subclass — the idiomatic `class ApiResponse extends JsonResponse` shape. It is
 * still transport rather than a body, and it is the case the guard's list alone would miss, so it must be
 * loadable in-process for the subclass clause to be exercised for real.
 */
final class CustomJsonResponse extends JsonResponse {}
