<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ShortNameResponse;

use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\WidgetData;

/**
 * A controller whose `#[Response]` names its body type by the SHORT class name `WidgetData` — the
 * idiomatic form a real Scramble migration leans on — resolved through this file's `use` imports by the
 * extension's ImportContext. `WidgetData` is a namespace away, so the `use` (referenced only inside
 * the attribute string) is what carries the resolution; a failure would degrade the 200 body to a
 * bare object with no `$ref`, so the end-to-end assertion on the resolved component is the proof.
 */
final class PanelController
{
    #[Response(status: 200, type: 'WidgetData', description: 'The panel snapshot.')]
    public function show(): JsonResponse
    {
        return response()->json([]);
    }
}
