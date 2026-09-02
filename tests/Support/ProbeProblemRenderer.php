<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * An invokable exception renderer that says what its error is SENT as and not what it contains: the
 * content type is a literal on the call, while the payload comes back from a formatter resolved at run
 * time, so nothing static can say what shape it takes. The media type folds and the body does not, which
 * is the one population the golden beside this file stands in for.
 */
final class ProbeProblemRenderer
{
    public function __invoke(ModelNotFoundException $e): JsonResponse
    {
        return response()->json($this->body($e), 404, ['Content-Type' => 'application/problem+json']);
    }

    /** @return array<string, mixed> */
    private function body(ModelNotFoundException $e): array
    {
        /** @var array<string, mixed> $shape */
        $shape = config('probe.problem', ['detail' => $e->getMessage()]);

        return $shape;
    }
}
