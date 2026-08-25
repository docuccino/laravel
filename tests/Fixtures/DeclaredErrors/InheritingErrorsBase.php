<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\Response;

/**
 * A base controller declaring one error for every action under it — the ordinary Laravel shape, and
 * the half a child overrides on one action ({@see InheritingErrorsController}).
 */
#[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'BaseGone')]
abstract class InheritingErrorsBase
{
    public function inherits(): array
    {
        return [];
    }
}
