<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\MorphTypeValues;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Archive;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Media;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Photo;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Post;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Video;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;

/*
 * The values `getMorphClass()` can write for a relation's models, stated against the framework itself:
 * every row's expectation is what the models answer when asked.
 */
afterEach(function (): void {
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

it('answers what the framework writes, beside every class the answer read', function (): void {
    Relation::enforceMorphMap(['photo' => Photo::class, 'post' => Post::class, 'video' => Video::class], false);

    expect(MorphTypeValues::of([Media::class]))->toBe(['values' => [(new Photo)->getMorphClass()], 'classes' => [Photo::class, Post::class, Video::class, Media::class]])
        ->and(MorphTypeValues::of(null)['values'])->toBe(['photo', 'post', 'video']);

    // A mapped model that answers for itself opens the any-model set: its value is its own code's.
    Relation::enforceMorphMap(['archived' => Archive::class], true);
    expect(MorphTypeValues::of(null)['values'])->toBeNull()
        ->and((new Archive)->getMorphClass())->toBe('archived');
});

it('writes a pivot under its class name even where the map is enforced, as the framework does', function (): void {
    Relation::enforceMorphMap(['post' => Post::class], false);

    expect(MorphTypeValues::of([Pivot::class])['values'])->toBe([(new Pivot)->getMorphClass()])
        ->and((new Pivot)->getMorphClass())->toBe(Pivot::class);
});
