<?php

declare(strict_types=1);

use Docuccino\Laravel\Versioning\OasTypeNames;

/**
 * The type names a removal verb may spell, every one of them, plus the two suffixes and what an
 * unreadable spelling degrades to.
 *
 * It is a table, so it is walked rather than sampled: a name added to {@see OasTypeNames::NAMES} and
 * left unread here would be a spelling the reference page promises and the build refuses.
 */
it('reads every name the type keyword takes', function (string $name): void {
    expect(OasTypeNames::read($name))->toBe(['type' => $name]);
})->with(OasTypeNames::NAMES);

it('reads a list of every one of them', function (string $name): void {
    expect(OasTypeNames::read($name.'[]'))->toBe(['type' => 'array', 'items' => ['type' => $name]]);
})->with(OasTypeNames::NAMES);

it('reads a nullable one of every one of them', function (string $name): void {
    expect(OasTypeNames::read($name.'?'))->toBe(['type' => [$name, 'null']]);
})->with(OasTypeNames::NAMES);

it('reads the suffixes outermost first, and nests them', function (string $spelling, array $schema): void {
    expect(OasTypeNames::read($spelling))->toBe($schema);
})->with([
    'a nullable list' => ['string[]?', ['type' => ['array', 'null'], 'items' => ['type' => 'string']]],
    'a list of nullables' => ['string?[]', ['type' => 'array', 'items' => ['type' => ['string', 'null']]]],
    'a list of lists' => ['integer[][]', ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
    'a nullable said twice' => ['string??', ['type' => ['string', 'null']]],
    'written with room around it' => ['  boolean  ', ['type' => 'boolean']],
]);

/*
 * The degradation, executed. Every one of these is a spelling somebody could plausibly write, and each
 * has to read as nothing rather than as `string` — a type grammar that guesses is how a confidently
 * wrong schema gets published.
 */
it('reads nothing at all where the spelling is not one of them', function (string $spelling): void {
    expect(OasTypeNames::read($spelling))->toBeNull();
})->with([
    'a class' => ['App\\Support\\Money'],
    'a phpdoc shape' => ['array<string, int>'],
    'a php scalar name' => ['int'],
    'another php scalar name' => ['bool'],
    'the keyword null on its own' => ['null'],
    'a union' => ['string|int'],
    'nothing' => [''],
    'a suffix with nothing under it' => ['[]'],
    'a question mark with nothing under it' => ['?'],
    'a suffix over something unreadable' => ['int[]'],
    'a case that is not the one the keyword uses' => ['String'],
]);
