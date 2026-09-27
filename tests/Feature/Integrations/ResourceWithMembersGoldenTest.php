<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\EnvelopeController;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\SparseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\WithPropertyResource;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * The members a root resource's `with()` adds, locked in emitted bytes. No other golden carries a
 * resource with a `with()` of its own or a body that can be sent as `[]`, so none could move when those
 * reads change. The routes return the resource at the root, nest it inside another resource's
 * `toArray`, return two named collections, a resource whose every key is conditional, and one setting
 * the `$with` property; they are their own document, so this golden moves only when these answers do.
 */
afterEach(fn () => removeFragmentCacheDirs('resource-with'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $location)]);

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        EnvelopeController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ReleaseResource::class), $location)]),
        EnvelopeController::class.'::nested' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ArticleResource::class), $location)]),
        ReleaseResource::class.'::toArray' => $shape([new ArrayShapeField('tag', ScalarT::string())]),
        ReleaseResource::class.'::with' => $shape([
            new ArrayShapeField('meta', new ClassT('stdClass')),
            new ArrayShapeField('version', ScalarT::string()),
        ]),
        ArticleResource::class.'::toArray' => $shape([new ArrayShapeField('release', new ClassT(ReleaseResource::class))]),
        EnvelopeController::class.'::index' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ReleaseCollection::class), $location)]),
        EnvelopeController::class.'::linked' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(LinkedReleaseCollection::class), $location)]),
        ReleaseCollection::class.'::with' => $shape([
            new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('key', ScalarT::string())])),
        ]),
        LinkedReleaseCollection::class.'::toArray' => $shape([
            new ArrayShapeField('data', new ListT(new ClassT(ReleaseResource::class))),
            new ArrayShapeField('links', new ArrayShapeT([new ArrayShapeField('self', ScalarT::string())])),
        ]),
        EnvelopeController::class.'::sparse' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(SparseResource::class), $location)]),
        SparseResource::class.'::toArray' => $shape([
            new ArrayShapeField('tag', UnionT::of([ScalarT::string(), new ClassT(ResourceReflector::MISSING_VALUE)])),
        ]),
        EnvelopeController::class.'::configured' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(WithPropertyResource::class), $location)]),
        WithPropertyResource::class.'::toArray' => $shape([new ArrayShapeField('tag', ScalarT::string())]),
    ]);

    $this->routes = static function (Router $router): void {
        $router->get('api/zz-release', [EnvelopeController::class, 'show']);
        $router->get('api/zz-article', [EnvelopeController::class, 'nested']);
        $router->get('api/zz-releases', [EnvelopeController::class, 'index']);
        $router->get('api/zz-linked-releases', [EnvelopeController::class, 'linked']);
        $router->get('api/zz-sparse', [EnvelopeController::class, 'sparse']);
        $router->get('api/zz-configured', [EnvelopeController::class, 'configured']);
    };
});

it('emits the with() members of a root resource byte-identical to its committed golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('resource-with-members.uir.json', (new UirEmitter)->emit($result->document));

    $document = $result->document->toArray();
    $body = static fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

    // At the root, the members stand beside `data`; nested, the same resource contributes none.
    expect(array_keys($body('/api/zz-release')['properties']))->toBe(['data', 'meta', 'version'])
        ->and($body('/api/zz-release')['required'])->toBe(['data', 'meta', 'version'])
        ->and(array_keys($body('/api/zz-article')['properties']))->toBe(['data'])
        ->and(array_keys($document['components']['schemas']['ReleaseResource']['properties']))->toBe(['tag'])
        // A named collection keeping Laravel's toArray is a list of what it collects, found by name.
        ->and($document['components']['schemas']['ReleaseCollection']['items'])->toBe(['$ref' => '#/components/schemas/ReleaseResource'])
        ->and($body('/api/zz-releases')['required'])->toBe(['data', 'meta'])
        // One whose toArray already returns `data` is sent as that body, not wrapped again.
        ->and($body('/api/zz-linked-releases')['$ref'] ?? null)->toBe('#/components/schemas/LinkedReleaseCollection')
        // Every key conditional: Laravel filters them all out and sends `[]`, which the object rejects.
        ->and($document['components']['schemas']['SparseResource']['anyOf'][1])->toBe(['type' => 'array', 'maxItems' => 0])
        // A `$with` set on the class may say anything at runtime, so the envelope names only `data`.
        ->and(array_keys($body('/api/zz-configured')['properties']))->toBe(['data']);
});

it('keys the fragment on the file with() is written in, and a warm build equals a cold one', function (): void {
    $dir = fragmentCacheDir('resource-with');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    // The subclass mentions with() nowhere, so editing the base that declares it has to retire the
    // fragment too.
    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(fragmentEntries($dir)['get /api/zz-release']['dependencies'])
        ->toContain(dirname(__DIR__, 2).'/Fixtures/ApiResources/EnvelopedResource.php');
});
