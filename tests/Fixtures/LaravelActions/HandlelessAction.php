<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsController;

/**
 * A degenerate action that carries the controller trait but defines neither asController() nor
 * handle(), so method resolution falls through to the registered method verbatim — exercising the
 * `: $method` fallback in LaravelAction::controllerMethod() (G4). Not a shape real laravel-actions
 * usage produces, but the fallback is a defensive total-function guard worth covering.
 */
final class HandlelessAction
{
    use AsController;

    public function perform(): bool
    {
        return true;
    }
}
