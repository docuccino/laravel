<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

/**
 * A controller whose action type-hints {@see GateRequest}, so the FormRequest-class lookup finds it.
 * Only ever reflected.
 */
final class GateController
{
    public function store(GateRequest $request): void {}
}
