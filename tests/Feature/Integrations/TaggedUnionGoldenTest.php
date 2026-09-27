<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Attachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\AttachmentController;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\DraftAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\FileAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ForwardedAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ImageAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\LinkAttachment;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Plain classes told apart by a value each fixes, locked in emitted bytes. Nothing else in the golden
 * corpus returns a union of plain classes or a sealed interface, so nothing could have moved when the
 * union mapper learned to discriminate them.
 *
 * The class metadata is the engine's own reflection read of the fixture classes, not a hand-written
 * stand-in: which property is fixed, and to what, is the half of this a stub would only restate. The
 * route return types are scripted, as everywhere in this suite.
 */
function taggedUnionEngine(): TypeEngine
{
    $location = new SourceLocation('');
    $factory = new ClassMetadataFactory;
    $classes = [];
    foreach ([ImageAttachment::class, LinkAttachment::class, FileAttachment::class, ForwardedAttachment::class, DraftAttachment::class] as $class) {
        $classes[$class] = $factory->forClass(new ClassRef($class));
    }

    return WorkbenchEngine::make(
        classOverrides: $classes,
        analysisOverrides: [
            AttachmentController::class.'::index' => new ActionAnalysis(returns: [new ReturnSite(new ListT(new ClassT(Attachment::class)), $location)]),
            AttachmentController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(UnionT::of([new ClassT(ImageAttachment::class), new ClassT(LinkAttachment::class)]), $location)]),
            AttachmentController::class.'::draft' => new ActionAnalysis(returns: [new ReturnSite(UnionT::of([new ClassT(ImageAttachment::class), new ClassT(LinkAttachment::class), new ClassT(DraftAttachment::class)]), $location)]),
            AttachmentController::class.'::maybe' => new ActionAnalysis(returns: [new ReturnSite(UnionT::of([new ClassT(ImageAttachment::class), new ClassT(LinkAttachment::class), new NullT]), $location)]),
            AttachmentController::class.'::forwarded' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ForwardedAttachment::class), $location)]),
        ],
    );
}

/**
 * The routes. The forwarded member holds the sealed parent and its path sorts ahead of the list, so a
 * build meets the member first — the order in which a decision taken mid-build would read no body for
 * it. `$forwarded: false` leaves that route out.
 */
function taggedUnionRoutes(Router $router, bool $forwarded = true): void
{
    $router->get('api/zz-attachments/list', [AttachmentController::class, 'index']);
    $router->get('api/zz-attachments/one', [AttachmentController::class, 'show']);
    $router->get('api/zz-attachments/draft', [AttachmentController::class, 'draft']);
    $router->get('api/zz-attachments/maybe', [AttachmentController::class, 'maybe']);
    if ($forwarded) {
        $router->get('api/zz-attachments/forwarded', [AttachmentController::class, 'forwarded']);
    }
}

afterEach(fn () => removeFragmentCacheDirs('tagged-union'));

it('emits tagged unions of plain classes byte-identical to their committed goldens', function (): void {
    $result = localityBuild(taggedUnionRoutes(...), taggedUnionEngine(...));

    assertGolden('tagged-union.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('tagged-union.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('tagged-union.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));

    // What the goldens are for, said out loud.
    $document = emittedArray($result);
    $schemas = $document['components']['schemas'];
    $success = static fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

    // The sealed interface is a component of its own, discriminated over exactly what it permits.
    expect($success('/api/zz-attachments/list')['items'])->toBe(['$ref' => '#/components/schemas/Attachment'])
        ->and($schemas['Attachment']['discriminator'])->toEqual([
            'propertyName' => 'kind',
            'mapping' => [
                'file' => '#/components/schemas/FileAttachment',
                'forwarded' => '#/components/schemas/ForwardedAttachment',
                'image' => '#/components/schemas/ImageAttachment',
                'link' => '#/components/schemas/LinkAttachment',
            ],
        ])
        ->and($schemas['ImageAttachment']['properties']['kind'])->toEqual(['type' => 'string', 'const' => 'image'])
        ->and($schemas['ImageAttachment']['required'])->toContain('kind')
        // A declared union discriminates the same way, inline.
        ->and($success('/api/zz-attachments/one')['discriminator']['propertyName'])->toBe('kind')
        // One member takes its kind from the caller, so it may hold any value: no exclusivity, no oneOf —
        // and the two that do pin it are what make this a union written to be tagged, so it is reported.
        ->and($success('/api/zz-attachments/draft'))->toHaveKey('anyOf')
        ->and($success('/api/zz-attachments/draft'))->not->toHaveKey('discriminator')
        ->and(diagnosticsCoded($result->diagnostics, 'components.union-undiscriminated'))->toHaveCount(1)
        // A member holding the sealed parent is discriminated like the rest, and the parent it holds is
        // the one component, whichever of the two a route reached first.
        ->and($schemas['ForwardedAttachment']['properties']['original'])->toBe(['$ref' => '#/components/schemas/Attachment'])
        // Null is a value the server sends but carries no tag, so it sits beside the tagged oneOf rather
        // than inside it, where a client dispatching on `kind` would have no member to put it in.
        ->and($success('/api/zz-attachments/maybe')['anyOf'][0]['discriminator']['propertyName'])->toBe('kind')
        ->and($success('/api/zz-attachments/maybe')['anyOf'][1])->toBe(['type' => 'null']);

    // 3.0 has no `const`: the tag goes out as a one-value enum, and the discriminator survives.
    $downlevel = json_decode((new OpenApi30DownlevelEmitter)->emit($result->document), true, flags: JSON_THROW_ON_ERROR);
    expect($downlevel['components']['schemas']['ImageAttachment']['properties']['kind'])->toEqual(['type' => 'string', 'enum' => ['image']])
        ->and($downlevel['components']['schemas']['Attachment']['discriminator']['propertyName'])->toBe('kind');

    // 3.0 has no null type either: the null branch becomes `nullable` on the node, beside the tagged
    // oneOf — the closest 3.0 spelling, but 3.0.3's `nullable` adds null only beside a `type`, so a 3.0
    // reader may take it as the oneOf alone. That loose reading is named, as it is for any composition.
    $maybe = $downlevel['paths']['/api/zz-attachments/maybe']['get']['responses']['200']['content']['application/json']['schema'];
    $report = (new OpenApi30DownlevelEmitter)->emitWithReport($result->document)->report;
    expect($maybe['nullable'])->toBeTrue()
        ->and($maybe['discriminator']['propertyName'])->toBe('kind')
        ->and($maybe['oneOf'])->toHaveCount(2)
        ->and($maybe)->not->toHaveKey('anyOf')
        ->and(array_map(static fn ($d): string => $d->message, diagnosticsCoded($report->diagnostics, 'downlevel.nullable-composition')))
        ->toBe(['Moved the `{type: null}` branch at #/paths/~1api~1zz-attachments~1maybe/get/responses/200/content/application~1json/schema/anyOf onto the parent as `nullable: true`, which OpenAPI 3.0 reads loosely beside a composition.']);
});

it('publishes the sealed parent the same whether or not a route reaches its member first', function (): void {
    // Adding the forwarded route puts a class that holds the parent ahead of every route returning the
    // parent itself. The parent's schema is a function of the classes, so adding it may add components
    // but must not change the one already there.
    $without = emittedArray(localityBuild(static fn (Router $router) => taggedUnionRoutes($router, forwarded: false), static fn (): TypeEngine => taggedUnionEngine()));
    $with = emittedArray(localityBuild(taggedUnionRoutes(...), taggedUnionEngine(...)));

    $attachment = $with['components']['schemas']['Attachment'];
    expect($attachment)->toBe($without['components']['schemas']['Attachment'])
        ->and($attachment)->toHaveKey('discriminator');
});

it('reports an undiscriminated union on a warm build too', function (): void {
    fragmentCacheDir('tagged-union');

    $cold = localityBuild(taggedUnionRoutes(...), taggedUnionEngine(...));
    $warm = localityBuild(taggedUnionRoutes(...), taggedUnionEngine(...), $counting);

    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticsCoded($cold->diagnostics, 'components.union-undiscriminated'))->not->toBeEmpty()
        ->and(diagnosticsCoded($warm->diagnostics, 'components.union-undiscriminated'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'components.union-undiscriminated'));
});
