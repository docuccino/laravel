<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * A wrapped payload class sharing a file with the small problem class it is returned beside — a pairing
 * apps write, and the reason a wrap read has to be scoped to one class's own declarations. Nothing here
 * touches its own envelope; the `withoutWrapping()` below belongs to its neighbour.
 */
final class SiblingWrapData extends Data
{
    public function __construct(
        public int $id,
    ) {}
}

/**
 * The neighbour: an RFC 9457 body that has to sit at the root, so it strips the envelope on its way out.
 */
final class SiblingWrapProblemData extends Data
{
    public function __construct(
        public string $type,
        public int $status,
    ) {}

    public function toProblemResponse(Request $request): JsonResponse
    {
        return $this->withoutWrapping()->toResponse($request);
    }
}
