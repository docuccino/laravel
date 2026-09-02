<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/** Two endpoints refused the same way, which is what puts their 409 over the sharing threshold. */
final class ExportController
{
    /** @return array{ok: bool} */
    public function archive(): array
    {
        return ['ok' => true];
    }

    /** @return array{ok: bool} */
    public function ledger(): array
    {
        return ['ok' => true];
    }
}
