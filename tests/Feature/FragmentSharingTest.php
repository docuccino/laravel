<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * One stored fragment serves every document that shapes its routes alike, so an application serving V
 * versions of R routes stores and analyses R rather than R × V. Identities were the only thing making
 * those fragments differ and they are stamped on the way OUT of the cache now, which puts the whole of
 * the risk in one place: a version being served another version's identity tree. Most of what is below
 * is about that.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('sharing');
});

it('builds every version of a route set for what the first version cost', function (): void {
    $dir = fragmentCacheDir('sharing');
    setDocuments(sharedVersionDocuments(4));
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $keys = array_keys(sharedVersionDocuments(4));
    generateDocument(key: array_shift($keys));

    // The floors are there because a route set that stopped being discovered would otherwise satisfy
    // "the other three cost nothing" by there being nothing to cost.
    $asks = $engine->analyzeCount;
    $entries = fragmentCount($dir);
    expect($asks)->toBeGreaterThan(10)
        ->and($entries)->toBeGreaterThan(10);

    foreach ($keys as $key) {
        generateDocument(key: $key);
    }

    expect($engine->analyzeCount)->toBe($asks)
        ->and(fragmentCount($dir))->toBe($entries);
});

it('gives each version its own identity tree though they share the fragments', function (): void {
    fragmentCacheDir('sharing');
    setDocuments(sharedVersionDocuments(3));
    bindStubEngine();

    $keys = array_keys(sharedVersionDocuments(3));

    // Warmed by each other: the second and third documents read entries the first wrote.
    $shared = array_map(
        static fn (string $key): array => publishedNodeIds(generateDocument(key: $key)->document),
        $keys,
    );

    // What "its own" means can only be settled by building each with nothing cached at all.
    $alone = [];
    foreach ($keys as $key) {
        fragmentCacheDir('sharing');
        $alone[] = publishedNodeIds(generateDocument(key: $key)->document);
    }

    expect($shared[0])->not->toBe($shared[1])
        ->and($shared[1])->not->toBe($shared[2])
        ->and($shared[0])->toBe($alone[0])
        ->and($shared[1])->toBe($alone[1])
        ->and($shared[2])->toBe($alone[2])
        ->and(count($shared[0]))->toBeGreaterThan(20);
});

it('says the same thing warm as cold, in bytes and in diagnostics, for every version', function (): void {
    setDocuments(sharedVersionDocuments(3));
    bindStubEngine();

    $keys = array_keys(sharedVersionDocuments(3));

    // Cold: a directory of its own per document, so nothing is warmed by a sibling.
    $cold = [];
    foreach ($keys as $key) {
        fragmentCacheDir('sharing');
        $result = generateDocument(key: $key);
        $cold[$key] = [(new UirEmitter)->emit($result->document), diagnosticLines($result->diagnostics)];
    }

    // Warm: one directory for all three, filled once — so the second pass reads entries the first pass
    // wrote, most of them written by a DIFFERENT version of the document.
    fragmentCacheDir('sharing');
    foreach ($keys as $key) {
        generateDocument(key: $key);
    }

    foreach ($keys as $key) {
        $result = generateDocument(key: $key);

        expect((new UirEmitter)->emit($result->document))->toBe($cold[$key][0])
            ->and(diagnosticLines($result->diagnostics))->toBe($cold[$key][1])
            ->and($cold[$key][1])->not->toBeEmpty();
    }
});

it('keeps a document that reads recorded examples on entries of its own', function (): void {
    // A recording is filed under the operation's DOCUMENT-scoped identity, so a build that reads one
    // reads the document itself and the two versions can no longer answer for each other.
    $dir = fragmentCacheDir('sharing');
    $documents = sharedVersionDocuments(2);
    foreach ($documents as $key => $document) {
        $documents[$key] = [...$document, 'examples' => ['recordings' => 'tests/Fixtures/recordings']];
    }
    setDocuments($documents);
    bindStubEngine();

    $keys = array_keys($documents);
    generateDocument(key: array_shift($keys));
    $entries = fragmentCount($dir);

    generateDocument(key: array_shift($keys));

    expect($entries)->toBeGreaterThan(10)
        ->and(fragmentCount($dir))->toBe($entries * 2);
});

it('keeps a document resolving an extension nobody here wrote on entries of its own', function (): void {
    $dir = fragmentCacheDir('sharing');
    setDocuments(sharedVersionDocuments(2));
    bindStubEngine();
    Docuccino::extend(applicationOwnedExtension());

    $summaries = [];
    $keys = array_keys(sharedVersionDocuments(2));
    foreach ($keys as $i => $key) {
        foreach (generateDocument(key: $key)->document->toArray()['paths'] as $operations) {
            foreach ($operations as $operation) {
                if (is_array($operation) && is_string($operation['summary'] ?? null)) {
                    $summaries[] = $operation['summary'];
                }
            }
        }

        if ($i === 0) {
            $entries = fragmentCount($dir);
        }
    }

    // The extension has to be RUNNING, or this is a test of nothing.
    expect($summaries)->toContain('written by the application')
        ->and($entries ?? 0)->toBeGreaterThan(10)
        ->and(fragmentCount($dir))->toBe(($entries ?? 0) * 2);
});

it('stores the very same bytes under the very same key whatever version asked for them', function (): void {
    setDocuments(sharedVersionDocuments(2));
    bindStubEngine();

    // Each built alone, so neither can be reading the other's entries — the claim is that they WROTE
    // the same thing, not that the second one was spared writing anything.
    $written = [];
    foreach (array_keys(sharedVersionDocuments(2)) as $key) {
        $dir = fragmentCacheDir('sharing');
        generateDocument(key: $key);

        $entries = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $entries[basename($file)] = (string) file_get_contents($file);
        }
        ksort($entries);
        $written[] = $entries;
    }

    expect(count($written[0]))->toBeGreaterThan(10)
        ->and($written[1])->toBe($written[0]);
});
