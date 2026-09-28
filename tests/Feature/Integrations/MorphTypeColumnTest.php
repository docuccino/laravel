<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Archive;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Bookmark;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Comment;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Flag;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Like;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Mention;
use Docuccino\Laravel\Tests\Fixtures\MorphType\MorphTypeController;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Note;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Photo;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Post;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Reaction;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Upload;
use Docuccino\Laravel\Tests\Fixtures\MorphType\User;
use Docuccino\Laravel\Tests\Fixtures\MorphType\Video;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;

/*
 * A `morphTo`'s type column holds whatever `getMorphClass()` answers for the model it points at: the
 * model's FIRST alias in the morph map, else its class name — unless the map is enforced, when an
 * unmapped model throws instead of being written. Where that makes the column's values a closed set, the
 * document publishes it as an enum; where it doesn't, the column keeps its declared type.
 */
afterEach(function (): void {
    // Both halves are static on Relation and outlive the test; TestCase re-registers its own map.
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
    removeFragmentCacheDirs('morph-type');
});

it('publishes an enforced map\'s aliases for the models the relation names', function (): void {
    Relation::enforceMorphMap(['post' => Post::class, 'video' => Video::class, 'user' => User::class, 'photo' => Photo::class], false);

    expect(modelProperties(Comment::class)['commentable_type'])->toBe([
        'type' => 'string',
        'enum' => ['post', 'video'],
        'x-enum-varnames' => ['Post', 'Video'],
        'x-enumNames' => ['Post', 'Video'],
    ]);

    // The contract, stated by the framework rather than by the mapper: associating each of the
    // relation's models writes exactly the published values.
    $written = [];
    foreach ([Post::class, Video::class] as $model) {
        $written[] = (new Comment)->commentable()->associate(new $model)->getAttribute('commentable_type');
    }
    sort($written);
    expect($written)->toBe(modelProperties(Comment::class)['commentable_type']['enum']);
});

it('publishes the whole enforced map where the relation names any model', function (): void {
    Relation::enforceMorphMap(['post' => Post::class, 'video' => Video::class, 'user' => User::class, 'photo' => Photo::class], false);

    // Nothing narrows `MorphTo<Model, $this>`, and an unmapped model cannot be written, so the map is the set.
    expect(modelProperties(Reaction::class)['reactable_type']['enum'])->toBe(['photo', 'post', 'user', 'video']);
});

it('keeps the declared nullability, and reads a type column named explicitly', function (): void {
    Relation::enforceMorphMap(['post' => Post::class, 'user' => User::class], false);

    // Null is a value the column holds, so it is admitted beside the set rather than folded into a
    // `type` the enum would still refuse it under.
    expect(modelProperties(Bookmark::class)['target_kind'])->toBe(['anyOf' => [
        [
            'type' => 'string',
            'enum' => ['post', 'user'],
            'x-enum-varnames' => ['Post', 'User'],
            'x-enumNames' => ['Post', 'User'],
        ],
        ['type' => 'null'],
    ]]);
});

it('reaches an abstract target\'s mapped subclasses, and never the base itself', function (): void {
    Relation::enforceMorphMap(['photo' => Photo::class, 'post' => Post::class], false);

    expect(modelProperties(Like::class)['likeable_type']['enum'])->toBe(['photo']);
});

it('reads a relation a model takes from a trait', function (): void {
    Relation::enforceMorphMap(['user' => User::class, 'post' => Post::class], false);

    expect(modelProperties(Upload::class)['owner_type']['enum'])->toBe(['user']);
});

it('publishes the first alias a model is mapped under, as the framework writes', function (): void {
    Relation::enforceMorphMap(['article' => Post::class, 'post' => Post::class, 'video' => Video::class], false);

    expect(modelProperties(Comment::class)['commentable_type']['enum'])->toBe(['article', 'video'])
        ->and((new Post)->getMorphClass())->toBe('article');
});

it('publishes an integer alias as the string the column holds', function (): void {
    Relation::enforceMorphMap([7 => Post::class, 9 => Video::class], false);

    expect(modelProperties(Comment::class)['commentable_type'])->toMatchArray([
        'type' => 'string',
        'enum' => ['7', '9'],
        'x-enum-varnames' => ['_7', '_9'],
    ]);
});

it('publishes an unmapped final model under its class name, where the map is not enforced', function (): void {
    Relation::morphMap(['post' => Post::class], false);

    expect(modelProperties(Comment::class)['commentable_type'])->toBe([
        'type' => 'string',
        'enum' => [Video::class, 'post'],
        'x-enum-varnames' => ['DocuccinoLaravelTestsFixturesMorphTypeVideo', 'Post'],
        'x-enumNames' => ['DocuccinoLaravelTestsFixturesMorphTypeVideo', 'Post'],
    ])->and((new Video)->getMorphClass())->toBe(Video::class);
});

it('publishes class names where there is no morph map at all', function (): void {
    expect(modelProperties(Comment::class)['commentable_type']['enum'])->toBe([Post::class, Video::class]);
});

it('keeps the declared type wherever the set is open', function (string $model, string $column, bool $enforced): void {
    $map = ['post' => Post::class, 'video' => Video::class, 'user' => User::class, 'photo' => Photo::class, 'archived' => Archive::class];
    $enforced ? Relation::enforceMorphMap($map, false) : Relation::morphMap($map, false);

    expect(modelProperties($model)[$column])->toBe(['type' => 'string']);
})->with([
    // Without enforcement an unmapped model writes its class name, and a class open to subclasses
    // (or abstract, so it is only ever one) has class names no build can list.
    'unenforced, any model' => [Reaction::class, 'reactable_type', false],
    'unenforced, a target open to subclasses' => [Upload::class, 'owner_type', false],
    'unenforced, an abstract target' => [Like::class, 'likeable_type', false],
    // A model that answers getMorphClass() itself writes what its code says, map or no map.
    'a target naming its own morph type' => [Flag::class, 'flaggable_type', true],
    // A relation whose name is computed might own any column on the model, this one included.
    'a sibling relation whose column is computed' => [Note::class, 'subject_type', true],
    // The accessor decides what is serialised, not the column.
    'an accessor over the column' => [Mention::class, 'mentionable_type', true],
]);

it('publishes no enum where the relation\'s models are all outside an enforced map', function (): void {
    // Every write would throw, so no value can reach the column: an empty enum would reject every row,
    // and the build has misread something rather than found a column that holds nothing.
    Relation::enforceMorphMap(['user' => User::class], false);

    expect(modelProperties(Comment::class)['commentable_type'])->toBe(['type' => 'string'])
        ->and(static fn () => (new Post)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});

/** The fixture routes: one per model whose type column the document describes. */
function morphTypeRoutes(Router $router): void
{
    foreach (['comment', 'reaction', 'bookmark', 'like', 'flag'] as $method) {
        $router->get('api/zz-morph/'.$method, [MorphTypeController::class, $method]);
    }
}

/** Each fixture model's columns read by the engine's own reflection of the class. */
function morphTypeEngine(): TypeEngine
{
    $location = new SourceLocation('');
    $factory = new ClassMetadataFactory;
    $models = ['comment' => Comment::class, 'reaction' => Reaction::class, 'bookmark' => Bookmark::class, 'like' => Like::class, 'flag' => Flag::class];

    $classes = [];
    $analyses = [];
    foreach ($models as $method => $model) {
        $classes[$model] = $factory->forClass(new ClassRef($model));
        $analyses[MorphTypeController::class.'::'.$method] = new ActionAnalysis(returns: [new ReturnSite(new ClassT($model), $location)]);
    }

    return WorkbenchEngine::make(classOverrides: $classes, analysisOverrides: $analyses);
}

it('emits the morph type columns byte-identical to their committed goldens', function (): void {
    Relation::enforceMorphMap(['post' => Post::class, 'video' => Video::class, 'user' => User::class, 'photo' => Photo::class], false);

    $result = localityBuild(morphTypeRoutes(...), morphTypeEngine(...));

    assertGolden('morph-type.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('morph-type.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('morph-type.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));

    $schemas = emittedArray($result)['components']['schemas'];
    expect($schemas['Comment']['properties']['commentable_type']['enum'])->toBe(['post', 'video'])
        ->and($schemas['Reaction']['properties']['reactable_type']['enum'])->toBe(['photo', 'post', 'user', 'video'])
        ->and($schemas['Bookmark']['properties']['target_kind']['anyOf'][1])->toBe(['type' => 'null'])
        ->and($schemas['Like']['properties']['likeable_type']['enum'])->toBe(['photo'])
        ->and($schemas['Flag']['properties']['flaggable_type'])->toBe(['type' => 'string']);
});

it('rebuilds warm under a changed morph map exactly as it builds cold', function (): void {
    fragmentCacheDir('morph-type');
    Relation::enforceMorphMap(['post' => Post::class, 'video' => Video::class], false);
    localityBuild(morphTypeRoutes(...), morphTypeEngine(...));

    // The same classes under a renamed alias: no file changed, only the booted map.
    Relation::enforceMorphMap(['article' => Post::class, 'video' => Video::class], false);
    $warm = localityBuild(morphTypeRoutes(...), morphTypeEngine(...), $counting);

    fragmentCacheDir('morph-type');
    $cold = localityBuild(morphTypeRoutes(...), morphTypeEngine(...));

    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(emittedArray($warm)['components']['schemas']['Comment']['properties']['commentable_type']['enum'])->toBe(['article', 'video']);

    // And the enforcement flag alone: the same map, no longer enforced, opens the any-model column.
    Relation::requireMorphMap(false);
    $unenforced = localityBuild(morphTypeRoutes(...), morphTypeEngine(...));
    expect(emittedArray($unenforced)['components']['schemas']['Reaction']['properties']['reactable_type'])->toBe(['type' => 'string']);
});
