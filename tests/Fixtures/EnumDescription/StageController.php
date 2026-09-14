<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumDescription;

/**
 * A controller whose action type-hints {@see StageRequest}, so the FormRequest-class lookup finds it.
 * Only ever reflected.
 */
final class StageController
{
    public function store(StageRequest $request): void {}
}
