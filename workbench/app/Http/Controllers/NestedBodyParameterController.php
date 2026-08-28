<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\UpdateScoringRequest;

/**
 * Names a nested body key by its dotted path — the same grammar the validation rules beside it are
 * written in — where the key's own rules leave it a free-form map with nothing to enumerate.
 */
final class NestedBodyParameterController
{
    #[Group('Widgets')]
    #[BodyParameter(name: 'meta.scoring.scores', type: 'object', description: 'Scores keyed by criterion id.')]
    public function update(UpdateScoringRequest $request): JsonResponse
    {
        return response()->json([]);
    }
}
