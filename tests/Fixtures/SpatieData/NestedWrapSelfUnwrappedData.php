<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * A class that takes its OWN envelope off while holding a nested collection. Spatie's two switches are
 * not the same axis: `withoutWrapping()` writes the object's `Wrap`, which decides the root and
 * nothing else, while a `WrapExecutionType` rides the transformation and gates every level under it.
 * So this root is bare and the collection inside it is still wrapped. Only ever reflected.
 */
final class NestedWrapSelfUnwrappedData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}

    public function toBareResponse(Request $request): JsonResponse
    {
        return $this->withoutWrapping()->toResponse($request);
    }
}
