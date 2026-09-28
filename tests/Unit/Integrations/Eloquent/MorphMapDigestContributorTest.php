<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\MorphMapDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Post;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Video;
use Illuminate\Database\Eloquent\Relations\Relation;

/*
 * The morph map's digest keys what the type-column reader consumes: the enforcement flag and each
 * model's resolved (first) alias. A reorder the reader cannot see must not churn the cache, and one it can
 * must.
 */
afterEach(function (): void {
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

it('keys the enforcement flag and each model\'s first alias, and nothing the reader cannot see', function (): void {
    $digest = static function (array $map, bool $enforced = false): string {
        Relation::requireMorphMap($enforced);
        Relation::morphMap($map, false);

        return (new MorphMapDigestContributor)->digest();
    };

    $base = $digest(['post' => Post::class, 'video' => Video::class]);

    // Two models registered in the other order resolve alike, so they hash alike.
    expect($digest(['video' => Video::class, 'post' => Post::class]))->toBe($base)
        // Enforcement alone changes what an unmapped model can write.
        ->and($digest(['post' => Post::class, 'video' => Video::class], true))->not->toBe($base)
        // A renamed alias is a different value in the column.
        ->and($digest(['article' => Post::class, 'video' => Video::class]))->not->toBe($base)
        // A model under two aliases writes the FIRST, so the order of those two is the answer.
        ->and($digest(['article' => Post::class, 'post' => Post::class]))->not->toBe($digest(['post' => Post::class, 'article' => Post::class]))
        // No map at all is a map too.
        ->and($digest([]))->toBe("enforced\x000\x00aliases");
});
