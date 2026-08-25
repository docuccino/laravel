<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;

/**
 * The ordinary Laravel shape the flood measurement needs: one `#[ErrorComponent]` written once, on the
 * base every API controller extends. `AttributeCollector` walks parents, so every action under it sees
 * the declaration.
 */
#[ErrorComponent('ApiError')]
abstract class ApiBaseController
{
    public function a(): array
    {
        return [];
    }

    public function b(): array
    {
        return [];
    }

    public function c(): array
    {
        return [];
    }
}
