<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\CastsMethodReader;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Workbench\App\Enums\WidgetStatus;

/**
 * The casts() method reader statically folds a model's `casts()` literal return — string-literal casts
 * and `Enum::class` casts (resolved to their FQCN) — and degrades gracefully (empty map) when there is
 * no method, no file, or the return is not a flat literal array.
 */
it('reads string and enum ::class casts from a casts() method', function (): void {
    $file = (new ReflectionClass(Invoice::class))->getFileName();

    expect((new CastsMethodReader)->read($file === false ? null : $file))->toBe([
        'issued_at' => 'datetime',
        'meta' => 'array',
        'status' => WidgetStatus::class,
    ]);
});

it('returns an empty map for a model with no casts() method', function (): void {
    // Widget declares only a $casts property (no casts() method), so the reader finds nothing.
    $file = (new ReflectionClass(Widget::class))->getFileName();

    expect((new CastsMethodReader)->read($file === false ? null : $file))->toBe([]);
});

it('degrades to an empty map for a null or missing file', function (): void {
    expect((new CastsMethodReader)->read(null))->toBe([])
        ->and((new CastsMethodReader)->read('/no/such/file.php'))->toBe([]);
});
