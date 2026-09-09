<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/*
 * Fragment-cache soundness for `lint.leakage` (design §10). The safelist and the heuristics table decide
 * whether a recorded example is PUBLISHED at all — a body still holding what looks like a credential is
 * withheld — so they shape fragment bytes. Nothing keyed them: `lint.*` is deliberately top-level, so no
 * document's config bag holds it, and the extension carries the options inside a collaborator object,
 * which the resolved-extension signature reads as nothing but a class name.
 *
 * A value, not a file — so the instrument is a digest contributor rather than a dependency manifest, and
 * the rows read the KEYS the cache wrote rather than manifest freshness.
 */
beforeEach(function (): void {
    $this->recordings = base_path('docs/recordings-'.getmypid().'-'.bin2hex(random_bytes(6)));
    mkdir($this->recordings, 0777, true);
});

afterEach(function (): void {
    removeFragmentCacheDirs('leakage');
    foreach (glob($this->recordings.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->recordings);
});

it('rebuilds the operations a changed safelist decides, rather than serving the example it withheld', function (): void {
    $dir = $this->recordings;
    bindStubEngine();

    // The operation id the recording is filed under, from a build with no cache at all.
    setBuild('cache.enabled', false);
    $id = generateDocumentWithRecordings($dir)->document->toArray()['paths']['/api/forms']['get']['x-docuccino']['id'];
    expect($id)->toBeString();

    // A committed body that still looks like it holds a credential: published only if a pointer for it
    // is safelisted, withheld otherwise. The whole example turns on one config value.
    (new RecordingStore($dir))->put(ExampleRecording::of(
        (string) $id,
        'GET /api/forms',
        [RecordedExample::of('200', 'application/json', (object) ['api_key' => 'sk_live_abcdefghijklmnop'])],
    ));

    $example = static fn (array $document): mixed => $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'] ?? null;

    fragmentCacheDir('leakage');
    $cold = generateDocumentWithRecordings($dir);
    expect($example($cold->document->toArray()))->toBeNull();

    setBuild('lint.leakage.allow', ['/api_key']);
    $warm = generateDocumentWithRecordings($dir);

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('leakage');
    $truth = generateDocumentWithRecordings($dir);

    expect($example($warm->document->toArray()))->not->toBeNull()
        ->and([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)]);
});

it('rebuilds when a comma-joined safelist is rewritten as the list it was meant to be', function (): void {
    $dir = $this->recordings;
    bindStubEngine();

    setBuild('cache.enabled', false);
    $id = generateDocumentWithRecordings($dir)->document->toArray()['paths']['/api/forms']['get']['x-docuccino']['id'];

    (new RecordingStore($dir))->put(ExampleRecording::of(
        (string) $id,
        'GET /api/forms',
        [RecordedExample::of('200', 'application/json', (object) ['api_key' => 'sk_live_abcdefghijklmnop'])],
    ));

    $example = static fn (array $document): mixed => $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'] ?? null;

    // How a list gets written by somebody who read the option as comma-separated. It safelists nothing —
    // the entry is compared whole — so the example stays withheld.
    fragmentCacheDir('leakage');
    setBuild('lint.leakage.allow', ['/api_key,/reset_token']);
    $cold = generateDocumentWithRecordings($dir);
    expect($example($cold->document->toArray()))->toBeNull();

    // And the fix: the same two pointers as two entries, which does safelist them. A digest joining
    // entries on a comma read the two bags as one and served the withheld example back.
    setBuild('lint.leakage.allow', ['/api_key', '/reset_token']);
    $warm = generateDocumentWithRecordings($dir);

    // What a build with nothing cached says — the truth the warm one owes, diagnostics included.
    fragmentCacheDir('leakage');
    $truth = generateDocumentWithRecordings($dir);

    expect($example($warm->document->toArray()))->not->toBeNull()
        ->and([(new UirEmitter)->emit($warm->document), diagnosticRecords($warm->diagnostics)])
        ->toBe([(new UirEmitter)->emit($truth->document), diagnosticRecords($truth->diagnostics)]);
});

it('keys the heuristics table as well as the safelist', function (): void {
    $dir = fragmentCacheDir('leakage');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocumentWithRecordings($this->recordings);
    $before = fragmentKeys($dir);
    $engine->analyzeCount = 0;

    // A token added to the table teaches redaction a new member name, which can withhold an example
    // that published a moment ago. Same shape of input, same claim on the key.
    setBuild('lint.leakage.patterns', ['sortcode' => 'a bank sort code']);
    generateDocumentWithRecordings($this->recordings);

    expect(array_diff(fragmentKeys($dir), $before))->toHaveCount(count($before))
        ->and($engine->analyzeCount)->toBeGreaterThan(0);
});

it('leaves every fragment warm when the safelist is only re-ordered', function (): void {
    fragmentCacheDir('leakage');
    setBuild('lint.leakage.allow', ['/api_key', 'reset_token']);
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocumentWithRecordings($this->recordings);
    expect($engine->analyzeCount)->toBeGreaterThan(0);
    $engine->analyzeCount = 0;

    // The safelist is consulted by membership, so its order changes no answer — and a config bag rewritten
    // in another order must not cost a rebuild. The heuristics table is the opposite and is keyed as
    // written, because there a name matches when it CONTAINS a token and the first hit wins.
    setBuild('lint.leakage.allow', ['reset_token', '/api_key']);
    generateDocumentWithRecordings($this->recordings);

    expect($engine->analyzeCount)->toBe(0);
});

it('leaves every fragment warm when the leakage REPORT is switched off, and still stops reporting', function (): void {
    fragmentCacheDir('leakage');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $cold = generateDocumentWithRecordings($this->recordings);
    expect(diagnosticsCoded($cold->diagnostics, 'lint.data-leakage'))->not->toBe([]);
    $engine->analyzeCount = 0;

    // `enabled` turns a REPORT off and never reaches a fragment: redaction is handed its options with
    // the switch unhonoured, and the lint that reads it is a document transformer, re-run every build.
    // So flipping it must cost nothing and must still be obeyed on the warm build.
    setBuild('lint.leakage.enabled', false);
    $warm = generateDocumentWithRecordings($this->recordings);

    expect($engine->analyzeCount)->toBe(0)
        ->and(diagnosticsCoded($warm->diagnostics, 'lint.data-leakage'))->toBe([]);
});
