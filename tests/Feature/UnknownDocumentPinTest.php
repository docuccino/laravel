<?php

declare(strict_types=1);

use Docuccino\Attributes\InDocs;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Support\UnknownDocumentPins;
use Docuccino\Laravel\Tests\Fixtures\InDocs\MisspelledController;
use Docuccino\Laravel\Tests\Fixtures\InDocs\PinnedController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * `#[InDocs]` is an allow-list, so a key naming no configured document does not widen anything — it
 * fails the test for every document, and the route is in none of them. Nothing downstream can notice:
 * the only evidence is a route that is not there, which reads exactly like a route somebody meant to
 * keep out. These pin what is reported, once per key, and that it reaches the build's diagnostics.
 */
beforeEach(function (): void {
    config()->set('docuccino.documents', [
        'default' => [
            'info' => ['title' => 'API Documentation', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/*']],
        ],
        'admin' => [
            'info' => ['title' => 'Admin API', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/admin/*']],
        ],
    ]);
});

/**
 * @param  list<string>  $sites
 * @return list<array{0: string, 1: bool}>
 */
function recordedPins(InDocs $declaration, array $sites): array
{
    $pins = new UnknownDocumentPins;

    foreach ($sites as $site) {
        $pins->record($declaration, $site);
    }

    return array_map(
        static fn (object $diagnostic): array => [(string) $diagnostic->message, $diagnostic->severity === Severity::Warning],
        $pins->take(),
    );
}

it('reports one key that names no configured document, and nothing else', function (InDocs $declaration, int $count, array $fragments): void {
    $reported = recordedPins($declaration, ['GET api/things']);

    expect($reported)->toHaveCount($count);

    foreach ($fragments as $fragment) {
        expect(implode("\n", array_column($reported, 0)))->toContain($fragment);
    }
})->with([
    // The key exists, so nothing is wrong and nothing is said.
    'a key that matches' => [new InDocs('admin'), 0, []],
    // The mistake: no document by that name, so the allow-list admits the route to none of them.
    'a key that matches nothing' => [new InDocs('admn'), 1, ['names the document "admn", which is not configured', 'every route below is left out of every document', 'The configured documents are default, admin.']],
    // A document renamed in config keeps its old key only in the attribute.
    'a key gone stale after a rename' => [new InDocs('legacy'), 1, ['"legacy"']],
    // A half-written declaration: no document key is the empty string.
    'an empty key' => [new InDocs(''), 1, ['names the document "", which is not configured']],
    // One mistake, said once.
    'the same key twice' => [new InDocs('admn', 'admn'), 1, ['"admn"']],
    // A dead key beside a working one: the route IS in `admin`, so the strong clause must not be said.
    'a dead key beside a working one' => [new InDocs('admin', 'reprts'), 1, ['"reprts"']],
    // The declaration naming nothing at all — variadic, so this is legal and denies nothing.
    'a declaration naming no document' => [new InDocs, 0, []],
]);

it('says a route is left out of every document only where no key of the declaration matched', function (): void {
    $stranded = recordedPins(new InDocs('admn'), ['GET api/things'])[0][0];
    $dead = recordedPins(new InDocs('admin', 'reprts'), ['GET api/things'])[0][0];

    expect($stranded)->toContain('every route below is left out of every document')
        // The route is in `admin`, so saying it is in none would be a report the reader can disprove.
        ->and($dead)->not->toContain('left out of every document');
});

it('says a key once however many sites carry it, naming them in an order it chose', function (): void {
    // Sites arrive in router order, which is registration order — so the message would otherwise be a
    // function of where somebody put a `Route::get`. Recorded backwards, reported forwards.
    $reported = recordedPins(new InDocs('admn'), ['POST api/z', 'GET api/a', 'GET api/a']);

    expect($reported)->toHaveCount(1)
        ->and($reported[0][0])->toContain('It is written on GET api/a, POST api/z.')
        // Warning: the document is not wrong, but the author asked for a pin and got an exclusion.
        ->and($reported[0][1])->toBeTrue();
});

it('names the keys in an order it chose, not the order the walk met them', function (): void {
    // Two typos on one route: which is reported first would otherwise be the order the author happened
    // to write the arguments in, and a diagnostic list that reorders between runs reads as churn.
    $pins = new UnknownDocumentPins;
    $pins->record(new InDocs('zeta', 'alpha'), 'GET api/things');

    $keys = array_map(
        static fn (array $reported): string => (string) (preg_match('/"([^"]*)"/', $reported[0], $m) === 1 ? $m[1] : ''),
        array_map(static fn (object $d): array => [(string) $d->message], $pins->take()),
    );

    expect($keys)->toBe(['alpha', 'zeta']);
});

it('empties itself as it is read, so a second document does not inherit the first one\'s findings', function (): void {
    $pins = new UnknownDocumentPins;
    $pins->record(new InDocs('admn'), 'GET api/things');

    expect($pins->take())->toHaveCount(1)
        ->and($pins->take())->toBe([]);
});

/**
 * The `default` document's diagnostics with one code.
 *
 * @return list<string>
 */
function pinMessages(string $key): array
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.'.$key);
    $config = app(DocumentConfigFactory::class)->make($key, $raw, 'skeleton');
    $result = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class));

    return array_map(
        static fn (object $diagnostic): string => (string) $diagnostic->message,
        diagnosticsCoded($result->diagnostics, 'attribute.in-docs-unknown'),
    );
}

it('reports a controller-level typo once, naming every route it silently removed', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/misspelled', [MisspelledController::class, 'index']);
    $router->post('api/misspelled/other', [MisspelledController::class, 'show']);

    $messages = pinMessages('default');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"admn"')
        // Both routes, because the reader has to be able to find the one declaration behind them.
        ->and($messages[0])->toContain('It is written on GET /api/misspelled, POST /api/misspelled/other.');
});

it('reports the dead half of a declaration whose other half works', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/admin/pinned', [PinnedController::class, 'index']);
    $router->get('api/admin/pinned/one', [PinnedController::class, 'show']);

    // Built as `admin`, the document the working key names — so the route is present AND the dead key
    // is still reported, which is the case a per-route report could never tell apart.
    $messages = pinMessages('admin');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"reprts"')
        ->and($messages[0])->not->toContain('left out of every document');
});

it('says nothing for a build whose every pin names a configured document', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/admin/pinned', [PinnedController::class, 'index']);

    expect(pinMessages('admin'))->toBe([]);
});
