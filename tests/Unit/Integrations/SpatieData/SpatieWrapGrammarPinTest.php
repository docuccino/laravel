<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\WrapSightings;

/*
 * The integration activates on `class_exists`, which is a presence check and not a version check — so
 * the vocabulary the wrap read is written against is pinned against the package composer actually
 * resolved, rather than assumed. Each row is a construct {@see WrapResolver} names; an install where
 * one of them moved would have the read quietly answering in another version's dialect, and this
 * fails in CI on the resolved upgrade instead.
 *
 * The wrap vocabulary is the oldest part of laravel-data's surface — wrapping arrived in v2 and none
 * of these four has moved since — which is why the read needs no runtime version gate and this guard
 * is a pin rather than a degrade.
 */

it('pins the laravel-data wrap vocabulary the read is written against', function (): void {
    $version = InstalledVersions::getPrettyVersion('spatie/laravel-data');

    // A denominator worth having: without this the rows below would pass just as well against nothing.
    expect($version)->toBeString()
        ->and(class_exists(DataClassReflector::DATA))->toBeTrue();

    $found = [
        // The transformation-level switch, matched by FQCN after NameResolver.
        'WrapExecutionType enum' => enum_exists(WrapSightings::WRAP_EXECUTION_TYPE),
        'its Disabled case' => defined(WrapSightings::WRAP_EXECUTION_TYPE.'::Disabled'),
        // The object-level switch.
        'Data::withoutWrapping()' => method_exists(DataClassReflector::DATA, 'withoutWrapping'),
        // The key the global config is read under, in the package's own shipped defaults.
        'a wrap key in the shipped config' => array_key_exists(
            'wrap',
            require rtrim((string) InstalledVersions::getInstallPath('spatie/laravel-data'), '/').'/config/data.php',
        ),
    ];

    expect($found)->toBe(array_map(static fn (): bool => true, $found));

    // And the assumption the key read rests on: the base class declares no `defaultWrap()`, so
    // `method_exists` being true already means the app overrode it. Spatie discovers the hook the same
    // way, in `ContextableData`.
    expect(method_exists(DataClassReflector::DATA, 'defaultWrap'))->toBeFalse();
});
