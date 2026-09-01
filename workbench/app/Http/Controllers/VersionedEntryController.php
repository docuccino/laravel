<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Example;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\FormEntryData;

/**
 * A form-entry list whose head shape really omits a field where there is nothing to send — which is
 * what gives the "made optional" verb something a contract test can catch it getting wrong.
 */
final class VersionedEntryController
{
    /**
     * List submitted form entries.
     *
     * Returns the entries recorded against the published forms, with the time each was submitted
     * where it has been.
     */
    #[Group('Forms')]
    #[Response(status: 200, type: 'list<FormEntryData>', description: 'The recorded entries.')]
    #[Example(value: [['id' => 1, 'label' => 'Onboarding', 'submittedAt' => '2026-08-01T09:00:00Z']])]
    public function index(): JsonResponse
    {
        return response()->json([
            new FormEntryData(id: 1, label: 'Onboarding', submittedAt: '2026-08-01T09:00:00Z'),
            new FormEntryData(id: 2, label: 'Offboarding'),
        ]);
    }
}
