<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\CastsMethodReader;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Quire;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Workbench\App\Enums\WidgetStatus;

/**
 * The casts() method reader statically folds a model's `casts()` literal return — string-literal casts
 * and `Enum::class` casts (resolved to their FQCN) — and degrades gracefully (empty map) when there is
 * no method, no file, or the return is not a flat literal array.
 */
it('reads string and enum ::class casts from a casts() method', function (): void {
    expect((new CastsMethodReader)->read(Invoice::class))->toBe([
        'issued_at' => 'datetime',
        'meta' => 'array',
        'status' => WidgetStatus::class,
    ]);
});

it('returns an empty map for a model with no casts() method', function (): void {
    // Widget declares only a $casts property (no casts() method), so the reader finds nothing.
    expect((new CastsMethodReader)->read(Widget::class))->toBe([]);
});

it('degrades to an empty map for a class that is not loadable, or whose casts() has no file', function (): void {
    eval('namespace CastsMethodReaderTestEval; final class FileslessModel extends \\Illuminate\\Database\\Eloquent\\Model { protected function casts(): array { return [\'pages\' => \'integer\']; } }');

    expect((new CastsMethodReader)->read('No\\Such\\Model'))->toBe([])
        ->and((new CastsMethodReader)->read('CastsMethodReaderTestEval\\FileslessModel'))->toBe([]);
});

it('reads the casts() the model runs, not a neighbour\'s in the same file', function (): void {
    // Quire's file also declares QuireDraft, whose casts() comes last and types `pages` as a string.
    expect((new CastsMethodReader)->read(Quire::class))->toBe(['pages' => 'integer']);
});
