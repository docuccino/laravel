<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\OverriddenResponse;

use Docuccino\Attributes\Response;
use Workbench\App\Data\FormData;
use Workbench\App\Data\WidgetData;

/**
 * An action whose inferred 200 is replaced by a `#[Response]` naming another type. Inference hoists the
 * body it read off the return type before the attribute layer wins, so the type it named is referred to
 * by nothing but the trail of the value the declaration overrode.
 */
final class OverriddenResponseController
{
    #[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
    public function show(): FormData
    {
        return new FormData;
    }
}
