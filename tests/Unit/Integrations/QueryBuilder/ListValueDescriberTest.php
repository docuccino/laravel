<?php

declare(strict_types=1);

/**
 * Dataset coverage over the model-prose lookups the describer answers: an include name from its
 * relation method's docblock summary (snake→camel included), a sort name from the `@property`
 * summary the engine recovered — and every no-answer shape degrading to null, a method that is not
 * a relation included.
 */
it('answers an include name from the relation method docblock summary', function (string $name, ?string $expected): void {
    expect(almanacDescriber()->include($name))->toBe($expected);
})->with([
    'documented relation' => ['entries', 'The yearly entries, most recent first.'],
    'snake name reaches the camel method' => ['chief_editor', 'The compiler credited on the cover.'],
    'undocumented relation' => ['errata', null],
    'tag-only docblock has no prose' => ['appendices', null],
    'dotted path never hops to the related model' => ['entries.notes', null],
    'no such method' => ['ghosts', null],
    // An include is a request value: a name colliding with a model method must not publish that
    // method's author-facing prose — Illuminate's own, for anything the base model declares.
    'a project method that is not a relation' => ['circulation', null],
    'an Eloquent method the base model documents' => ['getTable', null],
]);

it('answers a sort or fields column from the @property summary the engine recovered', function (string $column, ?string $expected): void {
    expect(almanacDescriber()->column($column))->toBe($expected);
})->with([
    'described column' => ['title', 'The almanac\'s display title.'],
    'column with no prose' => ['issued_at', null],
    'unknown column' => ['ghost_column', null],
]);
