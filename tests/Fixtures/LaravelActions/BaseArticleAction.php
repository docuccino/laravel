<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * An abstract base that carries the AsAction trait, so a subclass IS an action without using the
 * trait itself — exercising LaravelAction's class_parents() trait-discovery limb (G4).
 */
abstract class BaseArticleAction
{
    use AsAction;
}
