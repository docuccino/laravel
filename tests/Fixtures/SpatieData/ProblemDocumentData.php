<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * A Data class that renders itself with the envelope stripped — the shape an RFC 9457 problem document has
 * to take in an app where `config('data.wrap')` is set globally, since a problem body must sit at the root.
 * The wrap resolver reads the `withoutWrapping()` call statically; nothing here is ever invoked.
 */
final class ProblemDocumentData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

    public function toProblemResponse(Request $request): JsonResponse
    {
        $response = $this->withoutWrapping()->toResponse($request);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
