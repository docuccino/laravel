<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Attachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\FileAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ForwardedAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ImageAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\LabelData;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\LinkAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Note;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\NoteController;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\PinnedLinkData;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Tag;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\TagController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * A sealed union, and a plain class, sent by a client and read by one. `LinkAttachment::$title` defaults
 * to null: a client may leave it out, and a response always writes it — so the link is two shapes, and
 * so is everything that reaches it: the union it is a member of, the forwarded member that holds the
 * union, the note that holds the link, and the Data class that holds it under another mapper. Each of those is one component per side, or the side that met it
 * first decides what the other publishes, and an unrelated route moves a request. Nothing else in the
 * golden corpus sends a class whose shape differs by side only through what it reaches.
 *
 * Class metadata is the engine's own reflection of the fixtures; the route return types are scripted.
 */
$engine = static function (): TypeEngine {
    $factory = new ClassMetadataFactory;
    $classes = [];
    foreach ([ImageAttachment::class, LinkAttachment::class, FileAttachment::class, ForwardedAttachment::class, Note::class, PinnedLinkData::class] as $class) {
        $classes[$class] = $factory->forClass(new ClassRef($class));
    }

    $location = new SourceLocation('');

    return WorkbenchEngine::make(
        classOverrides: $classes,
        analysisOverrides: [
            NoteController::class.'::attachment' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(Attachment::class), $location)]),
            NoteController::class.'::note' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(Note::class), $location)]),
            NoteController::class.'::pinned' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(PinnedLinkData::class), $location)]),
        ],
    );
};

/** @param list<string> $actions */
$routes = static fn (string ...$actions): Closure => static function (Router $router) use ($actions): void {
    foreach ($actions as $action) {
        $action === 'store'
            ? $router->post('api/zz-notes', [NoteController::class, 'store'])
            : $router->get('api/zz-notes/'.$action, [NoteController::class, $action]);
    }
};

afterEach(fn () => removeFragmentCacheDirs('sealed-per-side'));

$requestSide = ['AttachmentRequest', 'ForwardedAttachmentRequest', 'LinkAttachmentRequest', 'NoteRequest', 'PinnedLinkDataRequest', 'FileAttachment', 'ImageAttachment'];

it('publishes each side of a sealed union over the members of that side', function () use ($engine, $routes): void {
    $result = localityBuild($routes('store', 'attachment', 'note', 'pinned'), $engine);

    assertGolden('sealed-union-per-side.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('sealed-union-per-side.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));

    $document = emittedArray($result);
    $schemas = $document['components']['schemas'];
    $ref = static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];

    expect($document['paths']['/api/zz-notes']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toBe(['attachment' => $ref('AttachmentRequest'), 'note' => $ref('NoteRequest'), 'pinned' => $ref('PinnedLinkDataRequest')])
        // The members that differ by side are their request shapes, mapping included; the rest are shared.
        ->and($schemas['AttachmentRequest']['oneOf'])->toBe([$ref('FileAttachment'), $ref('ForwardedAttachmentRequest'), $ref('ImageAttachment'), $ref('LinkAttachmentRequest')])
        ->and($schemas['AttachmentRequest']['discriminator']['mapping']['link'])->toBe('#/components/schemas/LinkAttachmentRequest')
        ->and($schemas['Attachment']['oneOf'])->toBe([$ref('FileAttachment'), $ref('ForwardedAttachment'), $ref('ImageAttachment'), $ref('LinkAttachment')])
        // A class reaching a request shape is one: the forwarded member holds the request union, the note
        // the request link, and on the response side each holds the response one.
        ->and($schemas['ForwardedAttachmentRequest']['properties']['original'])->toBe($ref('AttachmentRequest'))
        ->and($schemas['ForwardedAttachment']['properties']['original'])->toBe($ref('Attachment'))
        ->and($schemas['NoteRequest']['properties']['link'])->toBe($ref('LinkAttachmentRequest'))
        ->and($schemas['Note']['properties']['link'])->toBe($ref('LinkAttachment'))
        // The same holds whichever mapper hoists the class that reaches it.
        ->and($schemas['PinnedLinkDataRequest']['properties']['link'])->toBe($ref('LinkAttachmentRequest'))
        ->and($schemas['PinnedLinkData']['properties']['link'])->toBe($ref('LinkAttachment'))
        // What makes the link two shapes: a client may leave the title out, and a response always sends it.
        ->and($schemas['LinkAttachmentRequest']['required'])->not->toContain('title')
        ->and($schemas['LinkAttachment']['required'])->toContain('title')
        ->and(array_keys($schemas))->not->toContain('FileAttachmentRequest', 'ImageAttachmentRequest');
});

it('leaves the request as a build without the response routes publishes it', function () use ($engine, $routes, $requestSide): void {
    $alone = emittedArray(localityBuild($routes('store'), $engine));
    $with = emittedArray(localityBuild($routes('store', 'attachment', 'note', 'pinned'), $engine));

    expect($with['paths']['/api/zz-notes'])->toBe($alone['paths']['/api/zz-notes']);
    foreach ($requestSide as $name) {
        expect($with['components']['schemas'][$name])->toBe($alone['components']['schemas'][$name]);
    }
});

it('leaves the responses as a build without the request route publishes them', function () use ($engine, $routes): void {
    $alone = emittedArray(localityBuild($routes('attachment', 'note', 'pinned'), $engine))['components']['schemas'];
    $with = emittedArray(localityBuild($routes('store', 'attachment', 'note', 'pinned'), $engine))['components']['schemas'];

    expect(array_intersect_key($with, $alone))->toBe($alone);
});

it('publishes the same document whichever route is registered first', function () use ($engine, $routes): void {
    $first = (new UirEmitter)->emit(localityBuild($routes('store', 'attachment', 'note', 'pinned'), $engine)->document);

    expect((new UirEmitter)->emit(localityBuild($routes('pinned', 'note', 'attachment', 'store'), $engine)->document))->toBe($first)
        ->and((new UirEmitter)->emit(localityBuild($routes('attachment', 'store', 'pinned', 'note'), $engine)->document))->toBe($first);
});

it('builds both sides warm as cold', function () use ($engine, $routes): void {
    assertWarmEqualsCold($routes('store', 'attachment', 'note', 'pinned'), $routes('store', 'attachment', 'note', 'pinned'), $engine);
});

it('keys the request on every class that decided its shape, the seal included', function () use ($engine, $routes): void {
    $dir = fragmentCacheDir('sealed-per-side');
    localityBuild($routes('store'), $engine);

    // Adding a member to the seal, or a default to the link, changes which shape the request publishes.
    expect(fragmentEntries($dir)['post /api/zz-notes']['dependencies'])->toContain(
        (string) (new ReflectionClass(Attachment::class))->getFileName(),
        (string) (new ReflectionClass(LinkAttachment::class))->getFileName(),
    );
});

it('weighs a reached class by the keys its own mapper publishes', function (): void {
    // A defaulted property makes a PLAIN class two shapes, by the key rule ClassTypeToSchema publishes with.
    // A Data class is published by its own mapper, one shape to both sides, so holding one is no reason for
    // a plain class to split — and a split there publishes a `TagRequest` identical to `Tag`.
    $engine = static function (): TypeEngine {
        $factory = new ClassMetadataFactory;

        return WorkbenchEngine::make(
            classOverrides: [Tag::class => $factory->forClass(new ClassRef(Tag::class)), LabelData::class => $factory->forClass(new ClassRef(LabelData::class))],
            analysisOverrides: [TagController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(Tag::class), new SourceLocation(''))])],
        );
    };
    $document = emittedArray(localityBuild(static function (Router $router): void {
        $router->post('api/zz-tags', [TagController::class, 'store']);
        $router->get('api/zz-tags/show', [TagController::class, 'show']);
    }, $engine));

    expect($document['paths']['/api/zz-tags']['post']['requestBody']['content']['application/json']['schema']['properties']['tag'])
        ->toBe(['$ref' => '#/components/schemas/Tag'])
        ->and(array_keys($document['components']['schemas']))->not->toContain('TagRequest', 'LabelDataRequest');
});
