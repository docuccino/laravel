<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Routing\OperationMatch;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\RawTextDiagnosticExtension;
use Illuminate\Routing\Router;

/*
 * Whether a name the application chose reaches the published document as text or as a control sequence,
 * asked of a producer that does nothing to help. {@see RawTextDiagnosticExtension} is written the way a
 * producer that has never heard of the invariant is written — the name goes straight into the sentence —
 * and `Diagnostic` is what has to make that safe. So these rows say nothing about which producers
 * remembered; they say the answer does not depend on it.
 *
 * The ROUTE is hostile too, and deliberately: `routeSignature` is the one field a diagnostic publishes
 * unescaped, so a fixture on a tame path would leave the exemption untested and reading as an accident.
 * A route whose own path carries every hazard is what makes the two halves visible — the sentence a
 * producer wrote, which is neutralised, and the key, which stays byte-identical to what the document
 * already publishes as its `paths` key and to what `docuccino:explain` matches against.
 *
 * The absence rows read the document DECODED rather than as bytes. The document is written with
 * `JSON_UNESCAPED_UNICODE`, so a C1 introducer and a direction override reach an artifact whole — but
 * `json_encode` escapes `\x1B` and U+2028 as transport whatever the flags, and hands them back whole on
 * the way out. A bytes-only row therefore cannot fail for those two, and two of these four once passed
 * with nothing escaping them at all. Decoding first is what a reader of the artifact does anyway.
 */

afterEach(function (): void {
    removeFragmentCacheDirs('diagescape');
});

it('makes safe what a producer stated raw, wherever the producer put it', function (): void {
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get(RawTextDiagnosticExtension::HOSTILE_PATH, fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    // Stated positively as well as negatively: a row that only checked for absence would pass just as
    // well on a message that had lost the name altogether.
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->code)->toBe('test.raw\\u{202E}edoc')
        ->and($reported[0]->message)->toBe('The name "Evil\\x1B[31m\\u{009B}31m\\u{202E}\\u{2028}\\x0D\\x0AName" was read as written.')
        ->and($reported[0]->help)->toBe("Correct it.\nIt is spelled \"Evil\\x1B[31m\\u{009B}31m\\u{202E}\\u{2028}Name\" today.");
});

it('keeps a help line break as layout', function (): void {
    // A newline is the one control character `help` keeps: a console writer indents and gutters each of
    // its lines past anything they could be mistaken for, and `json_encode` escapes a newline whatever
    // else it leaves alone.
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get(RawTextDiagnosticExtension::HOSTILE_PATH, fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    expect(substr_count((string) $reported[0]->help, "\n"))->toBe(1);
});

it('names the route with the same bytes the document publishes as its path', function (): void {
    // The exemption, stated as the equality it exists for. `routeSignature` is a KEY: `docuccino:explain`
    // filters this operation's diagnostics by comparing it against what the document says the operation
    // is, and it is sorted on. Escaping the published side alone would break that match and remove
    // nothing from the artifact — the same bytes stand in `paths`, and have to, because that key is the
    // URL a client sends. So the row is an identity between the two sides rather than an absence.
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get(RawTextDiagnosticExtension::HOSTILE_PATH, fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    $paths = array_keys(emittedArray($result)['paths']);

    // Otherwise the identity below could hold on two values that had both lost the hazard.
    expect($paths)->toBe(['/'.RawTextDiagnosticExtension::HOSTILE_PATH])
        ->and($reported[0]->routeSignature)->toBe((new OperationMatch('default', $paths[0], 'get'))->signature());
});

it('publishes no sequence that steers whatever renders the artifact, outside the route key', function (string $hazard): void {
    Docuccino::extend(new RawTextDiagnosticExtension);
    $result = localityBuild(static fn (Router $router) => $router->get(RawTextDiagnosticExtension::HOSTILE_PATH, fn (): array => ['ok' => true]));

    $reported = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'test.raw'),
    ));

    $document = $result->document->toArray();
    $document['x-docuccino']['diagnostics'] = array_map(static fn (Diagnostic $d): array => $d->toArray(), $reported);
    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($document));

    /** @var array{'x-docuccino': array{diagnostics: list<array<string, string>>}} $decoded */
    $decoded = json_decode($emitted, true, flags: JSON_THROW_ON_ERROR);
    $published = $decoded['x-docuccino']['diagnostics'];

    // Everything a producer WROTE, which is everything the constructor owns. The key it merely quotes
    // has its own row above.
    expect($published)->toHaveCount(1);
    $authored = $published[0];
    unset($authored['routeSignature']);

    // The positive control the absence needs: the name did reach the artifact, escaped, in all three.
    expect(array_keys($authored))->toBe(['severity', 'code', 'message', 'help'])
        ->and($authored['code'])->toContain('u{202E}')
        ->and($authored['message'])->toContain('u{202E}')
        ->and($authored['help'])->toContain('u{202E}')
        ->and(implode("\x00", $authored))->not->toContain($hazard);
})->with([
    'ANSI escape' => "\x1B",
    'C1 control sequence introducer' => "\u{009B}",
    'right-to-left override' => "\u{202E}",
    'line separator' => "\u{2028}",
]);

it('says the same thing on a warm build as on a cold one, rather than escaping the escapes again', function (): void {
    // Idempotence is the whole reason this can live at construction: `fromArray()` is how a diagnostic
    // comes back off a warm fragment-cache hit, and it comes back through the constructor. A second
    // escaping layer per rebuild would be invisible to any single build and obvious here.
    Docuccino::extend(new RawTextDiagnosticExtension);
    $routes = static fn (Router $router) => $router->get(RawTextDiagnosticExtension::HOSTILE_PATH, fn (): array => ['ok' => true]);

    fragmentCacheDir('diagescape');
    $coldEngine = null;
    $cold = localityBuild($routes, null, $coldEngine);

    $warmEngine = null;
    $warm = localityBuild($routes, null, $warmEngine);

    // Otherwise the row proves nothing: two cold builds agree whatever the constructor does to the text.
    assert($coldEngine instanceof CountingTypeEngine && $warmEngine instanceof CountingTypeEngine);
    expect($coldEngine->analyzeCount)->toBeGreaterThan(0)
        ->and($warmEngine->analyzeCount)->toBe(0);

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('diagescape');
    $truth = localityBuild($routes);

    expect([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)])
        ->and(diagnosticRecords($cold->diagnostics))->toBe(diagnosticRecords($truth->diagnostics));
});
