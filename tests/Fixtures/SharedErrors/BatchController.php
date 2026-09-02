<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/** Two endpoints refused the same way, which is what puts their 422 over the sharing threshold. */
final class BatchController
{
    /** @return array{ok: bool} */
    public function submit(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function replay(): array
    {
        return ['ok' => true];
    }
}
