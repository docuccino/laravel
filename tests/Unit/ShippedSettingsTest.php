<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped `docuccino.yaml`: that it parses, that it spells an empty collection out, and that its
 * documents line up with the viewers the framework config wires for them.
 *
 * The empty-collection rule is the one most likely to break silently, and it is not cosmetic. A blank
 * `servers:` parses to NULL where `servers: []` parses to an empty array, and `Json::stable()`
 * fingerprints those differently — so the same intent, written two ways, gives two `configHash` values,
 * changes emitted bytes and cold-busts every warm fragment. Nothing downstream can tell the two apart
 * afterwards, which is why it is checked here, at the file.
 */
function shippedSettingsFile(): string
{
    return dirname(__DIR__, 2).'/config/'.ConfigFile::NAME;
}

/** @return array<string, mixed> */
function shippedSettingsMap(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parse((string) file_get_contents(shippedSettingsFile()), ConfigFile::FLAGS);

    return $parsed;
}

/**
 * The framework config, loaded the way Laravel loads it.
 *
 * @return array<string, mixed>
 */
function shippedFrameworkConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/docuccino.php';

    return $config;
}

it('parses, as the reader parses it', function (): void {
    $read = ConfigFile::parse((string) file_get_contents(shippedSettingsFile()));

    expect($read->ok())->toBeTrue()
        ->and($read->diagnostics)->toBe([])
        // Enough of a file to be worth checking: a reader that silently stopped early would otherwise
        // satisfy every assertion below.
        ->and(array_keys($read->values))->toBe([
            'documents', 'extensions', 'lint', 'diagnostics', 'engine', 'on_route_error', 'cache',
        ]);
});

it('spells every empty collection out rather than leaving it blank', function (): void {
    $nulls = [];
    $walk = static function (array $bag, string $path) use (&$walk, &$nulls): void {
        foreach ($bag as $key => $value) {
            $name = $path === '' ? (string) $key : $path.'.'.$key;
            if ($value === null) {
                $nulls[] = $name;
            } elseif (is_array($value)) {
                $walk($value, $name);
            }
        }
    };
    $walk(shippedSettingsMap(), '');

    // The one key that MEANS present-and-empty. Everything else that holds a collection has to be
    // written `[]` or `{}`: a blank value parses to null, and null and [] hash differently.
    expect($nulls)->toBe(['documents.default.content.dir']);
});

it('defaults the engine mode to the in-process literal', function (): void {
    // The one setting whose default is a word the engine matches on. It moved files, and a document
    // built with no inference at all still looks plausible, so the literal is pinned at the file.
    expect(data_get(shippedSettingsMap(), 'engine.mode'))->toBe('in-process');
});

it('keys both shipped files by the same documents', function (): void {
    // Literal expected sets, not `array_keys($a) === array_keys($b)` — which two empty files satisfy.
    expect(array_keys(shippedSettingsMap()['documents']))->toBe(['default'])
        ->and(array_keys((array) shippedFrameworkConfig()['documents']))->toBe(['default']);

    // And the relation the two files owe each other, in the one direction that is a defect: a viewer
    // keyed by a document the build never defines registers routes that fail. The other direction —
    // a document with no viewer — is an export-only document and legitimate.
    expect(array_values(array_diff(
        array_keys((array) shippedFrameworkConfig()['documents']),
        array_keys(shippedSettingsMap()['documents']),
    )))->toBe([]);
});

it('names the same document ids the shipped viewer wiring does, with none left over', function (): void {
    $withViewer = [];

    foreach ((array) shippedFrameworkConfig()['documents'] as $key => $bag) {
        if (is_array($bag) && is_array($bag['viewer'] ?? null)) {
            $withViewer[] = (string) $key;
        }
    }

    expect($withViewer)->toBe(['default']);
});
