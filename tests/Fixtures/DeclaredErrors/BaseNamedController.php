<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

/** A child of the base that carries the declaration, with actions of its own. */
final class BaseNamedController extends ApiBaseController
{
    public function d(): array
    {
        return [];
    }

    public function e(): array
    {
        return [];
    }

    public function f(): array
    {
        return [];
    }
}
