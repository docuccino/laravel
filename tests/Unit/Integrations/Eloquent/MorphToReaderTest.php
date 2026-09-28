<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\MorphToReader;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Bookmark;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Comment;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Like;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Media;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Note;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Pin;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Post;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Reaction;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Share;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Upload;
use Docuccino\Laravel\Tests\Fixtures\MorphType\User;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Video;

/*
 * The static `morphTo` reader: the column each relation's morph type lives in — resolved as `morphTo()`
 * and `getMorphs()` resolve it — and the models the relation's `@return` generic names. A call whose
 * column can't be read is a refusal, carrying the column where only its targets were unreadable.
 */
it('reads each fixture model\'s morphTo relations', function (string $model, array $expected): void {
    expect((new MorphToReader)->relations($model))->toBe($expected);
})->with([
    'the default column, a union generic' => [Comment::class, ['readable' => ['commentable_type' => [Post::class, Video::class]], 'refused' => []]],
    'the framework\'s any-model generic names nothing' => [Reaction::class, ['readable' => ['reactable_type' => null], 'refused' => []]],
    '__FUNCTION__ and an explicit type column' => [Bookmark::class, ['readable' => ['target_kind' => [Post::class, User::class]], 'refused' => []]],
    'an abstract target, as declared' => [Like::class, ['readable' => ['likeable_type' => [Media::class]], 'refused' => []]],
    'a relation from a trait' => [Upload::class, ['readable' => ['owner_type' => [User::class]], 'refused' => []]],
    // A computed name leaves the column unknown, so it could be any; the readable sibling is still read.
    'a computed relation name' => [Note::class, ['readable' => ['subject_type' => [Post::class]], 'refused' => [null]]],
    // A named `type:`, a positional name, and a body choosing between two calls — each column still named.
    'the other spellings' => [Pin::class, ['readable' => ['pinned_type' => [Post::class], 'surface_type' => [Video::class], 'nulled_kind' => [Post::class]], 'refused' => ['left_type', 'right_type', null, null]]],
    // Two relations over one column: it holds what either writes, and nothing is known where either is unknown.
    'relations sharing a column' => [Share::class, ['readable' => ['shareable_type' => [Post::class, Video::class], 'shared_type' => null], 'refused' => []]],
    'a model with no morphTo' => [Post::class, ['readable' => [], 'refused' => []]],
    'not a class at all' => ['Docuccino\\Laravel\\Tests\\Fixtures\\MorphType\\Missing', ['readable' => [], 'refused' => []]],
]);
