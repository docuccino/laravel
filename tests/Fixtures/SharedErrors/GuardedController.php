<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/**
 * Six endpoints behind three guards — two endpoints per guard, which is what a guard is for — each
 * throwing its guard's exception and each answered by {@see GuardProblemRenderer} with one 403 problem
 * document.
 */
final class GuardedController
{
    /** @return array{ok: bool} */
    public function profile(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function settings(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function audit(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function billing(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function catalog(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function pricing(): array
    {
        return ['ok' => true];
    }
}
