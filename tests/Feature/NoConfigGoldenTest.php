<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The document an application with NO configuration file gets, byte-locked.
 *
 * The population none of the other goldens stands in. Every one of them configures its document — the
 * shipped bag with a key changed, or a `documents` bag of its own — so the whole of the fallback path
 * was uncommitted: the bag {@see ConfiguredDocuments::of()} resolves, and the document the readers
 * then build out of it.
 *
 * Built through {@see DocumentBuilder} rather than straight off a config bag, because resolving the
 * bag is half of what is on trial here.
 */
it('emits the no-configuration document byte-identical to its committed golden', function (): void {
    BuildSettings::none();
    bindStubEngine();

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    assertGolden('workbench-no-config.uir.json', (new UirEmitter)->emit($result->document));

    // Spot-checks over the emitted bytes, so a reader of this file can see what the lock is holding:
    // the shipped route filter, the shipped title, and the framework's own error shapes.
    $document = json_decode((new UirEmitter)->emit($result->document), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($document['paths']))->each->toStartWith('/api/')
        ->and($document['info']['title'])->toBe('API Documentation')
        ->and($document['info']['version'])->toBe('1.0.0');
});
