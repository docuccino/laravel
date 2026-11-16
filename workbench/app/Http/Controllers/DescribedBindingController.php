<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Workbench\App\Models\Form;
use Workbench\App\Models\Ledger;

/**
 * Bound segments their author described as well, so the route's own notes on how each resolves meet a
 * description written above them.
 */
final class DescribedBindingController
{
    /** Show one entry of a ledger. */
    #[Group('Bindings')]
    #[PathParameter('ledger', description: 'The ledger the entry was filed against.')]
    #[PathParameter('entry', description: 'The entry to show.')]
    public function showEntry(Ledger $ledger, Form $entry): JsonResponse
    {
        return response()->json($entry);
    }
}
