<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ConfiguredDocuments;

/**
 * The config half of API versioning: what makes a document a version, where its changes live, and the
 * closed set of versions the header enumerates.
 */
function versionedConfig(mixed $apiVersion, mixed $version = '2026-09-01'): DocumentConfig
{
    return new DocumentConfig(key: 'v', info: [], raw: ['api_version' => $apiVersion, 'info' => ['version' => $version]]);
}

it('is an API version whenever it declares one, whatever the bag holds', function (mixed $apiVersion, bool $declares): void {
    expect(versionedConfig($apiVersion)->declaresApiVersion())->toBe($declares);
})->with([
    'no bag' => [null, false],
    'a bag that is not a bag' => ['2026-09-01', false],
    'an empty bag' => [[], true],
    'a bag naming a changes directory' => [['changes' => ['app/Api/Versions']], true],
]);

it('states no version until info.version writes one', function (mixed $version): void {
    expect(versionedConfig([], $version)->apiVersion())->toBeNull();
})->with([
    'unset' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'not a string' => [20260901],
]);

/*
 * Written or not written is the whole test. `1.0.0` is the shipped default AND the likeliest first
 * semver version an API ever publishes, and nothing here can tell the two apart — the shipped config
 * writes the key, so after a publish the file says `1.0.0` for both reasons. Reading it as unstated
 * left an application whose first version really is `1.0.0` unable to use the feature at all: no
 * header, no enum, no change applied, and a warning it could do nothing about.
 */
it('takes 1.0.0 for the version it says it is, once a document opts into api_version', function (): void {
    expect(versionedConfig([], '1.0.0')->apiVersion())->toBe('1.0.0')
        // And the opt-in is what carries the intent: without an `api_version` bag it is still nothing.
        ->and((new DocumentConfig(key: 'v', info: [], raw: ['info' => ['version' => '1.0.0']]))->apiVersion())->toBeNull();
});

it('reads the version off info.version, which is the only place it is written', function (): void {
    expect(versionedConfig([], '2026-09-01')->apiVersion())->toBe('2026-09-01')
        ->and(versionedConfig([], '  2026-09-01  ')->apiVersion())->toBe('2026-09-01')
        // A document that is not a version has no API version, whatever its info says.
        ->and((new DocumentConfig(key: 'v', info: [], raw: ['info' => ['version' => '2026-09-01']]))->apiVersion())->toBeNull();
});

it('reads every configured changes directory, in the order they are written', function (): void {
    // A list, and the configured order, because the first entry is where a scaffolded change is
    // written — sorting here would move that answer around while nothing in config changed.
    expect(versionedConfig(['changes' => ['modules/Zebra/Api/Versions', 'app/Api/Versions']])->apiVersionChangeDirs())
        ->toBe(['modules/Zebra/Api/Versions', 'app/Api/Versions']);
});

it('holds no directory a document names none of, and refuses one no filesystem call could hold', function (mixed $changes): void {
    expect(versionedConfig(['changes' => $changes])->apiVersionChangeDirs())->toBe([]);
})->with([
    'unset' => [null],
    // The shape `overlays` refuses too: a list is the one spelling, so a bare string reads as nothing.
    'a string rather than a list' => ['app/Api/Versions'],
    'an empty list' => [[]],
    'an empty entry' => [['']],
    'a non-string entry' => [[false]],
    // A NUL byte reaches no `is_dir()` from here: the same refusal every other configured path gets.
    'a NUL byte' => [["app\0/Api"]],
]);

it('publishes X-Api-Version unless the document names another header', function (mixed $configured, string $header): void {
    expect(versionedConfig(['header' => $configured])->apiVersionHeader())->toBe($header);
})->with([
    'unset' => [null, 'X-Api-Version'],
    'blank' => ['   ', 'X-Api-Version'],
    'not a string' => [false, 'X-Api-Version'],
    'named' => ['Api-Version', 'Api-Version'],
    'padded' => ['  Api-Version  ', 'Api-Version'],
]);

it('reads the closed set of versions off the documents themselves, sorted', function (): void {
    config()->set('docuccino.documents', [
        'later' => ['api_version' => [], 'info' => ['version' => '2026-12-01']],
        'earlier' => ['api_version' => [], 'info' => ['version' => '2026-06-01']],
        // A document that is not a version contributes nothing, and neither does one that declares
        // api_version and writes no version at all — its own build says so.
        'plain' => ['info' => ['version' => '2027-01-01']],
        'unstated' => ['api_version' => [], 'info' => []],
        'duplicate' => ['api_version' => [], 'info' => ['version' => '2026-06-01']],
    ]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe(['2026-06-01', '2026-12-01']);
});

/*
 * The row a date-only suite could not fail on: dates are fixed-width, so byte order happens to be right
 * for them. Semver is where it is wrong — `1.10.0` sorts BEFORE `1.9.0` bytewise — and this is the set
 * an operation's header enum publishes.
 */
it('orders the set as versions rather than as bytes', function (): void {
    config()->set('docuccino.documents', [
        'ten' => ['api_version' => [], 'info' => ['version' => '1.10.0']],
        'nine' => ['api_version' => [], 'info' => ['version' => '1.9.0']],
        'two' => ['api_version' => [], 'info' => ['version' => '1.2.0']],
    ]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe(['1.2.0', '1.9.0', '1.10.0']);
});

it('falls back to byte order only for a set no version grammar reads', function (): void {
    config()->set('docuccino.documents', [
        'b' => ['api_version' => [], 'info' => ['version' => 'beta']],
        'a' => ['api_version' => [], 'info' => ['version' => '2026-06-01']],
    ]);

    // Neither all dates nor all semver: nothing can order them, and byte order is at least deterministic.
    expect((new ConfiguredDocuments)->apiVersions())->toBe(['2026-06-01', 'beta']);
});

it('enumerates nothing when the application configures no version', function (): void {
    config()->set('docuccino.documents', ['default' => ['info' => ['version' => '1.0.0']]]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe([]);
});
