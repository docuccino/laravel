<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Response;

/** One action overriding the base's name for the status, one taking it as it comes. */
final class InheritingErrorsController extends InheritingErrorsBase
{
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'ActionGone')]
    public function overrides(): array
    {
        return [];
    }
}
