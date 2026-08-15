<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\InheritedShapes\BaseEnvelopeResource;
use Docuccino\Laravel\Tests\Fixtures\InheritedShapes\EnvelopedResource;
use Docuccino\Laravel\Tests\Fixtures\InheritedShapes\InheritedController;

/**
 * Fragment-cache soundness for facts inheritance answers ({@see DeclarationFiles}): a static `$wrap`, a
 * `render()`, an action trait can all be declared a level up, and a fragment that records only the class
 * it was asked about survives an edit that changed its answer.
 *
 * These read the dependency list the cache actually stored rather than editing a tracked fixture: the
 * list is what freshness is checked against, so naming the file is the whole of the claim.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('inherited');
});

/**
 * Every dependency file the fragments written to $dir recorded, across all of them.
 *
 * @return list<string>
 */
function recordedDependencies(string $dir): array
{
    $files = [];
    foreach (glob($dir.'/*.json') ?: [] as $fragment) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($fragment), true, flags: JSON_THROW_ON_ERROR);
        foreach ($decoded['dependencies'] ?? [] as $dependency) {
            $files[] = $dependency['file'];
        }
    }

    return array_values(array_unique($files));
}

it('records the file that declares an inherited fact, not just the class it was asked about', function (): void {
    $dir = fragmentCacheDir('inherited');

    app('router')->get('api/enveloped', [InheritedController::class, 'enveloped']);
    app()->instance(TypeEngine::class, new StubTypeEngine(analyses: [
        InheritedController::class.'::enveloped' => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT(EnvelopedResource::class), new SourceLocation(''))],
        ),
    ]));

    $document = generateDocument()->document->toArray();
    $recorded = recordedDependencies($dir);

    // The envelope really is inherited — otherwise the row would be about a file nothing reads.
    expect($document['paths']['/api/enveloped']['get']['responses']['200']['content']['application/json']['schema']['properties'] ?? [])
        ->toHaveKey('envelope')
        ->and($recorded)->not->toBeEmpty()
        ->and($recorded)->toContain((string) (new ReflectionClass(BaseEnvelopeResource::class))->getFileName())
        // …and the class the question was asked about is there too.
        ->and($recorded)->toContain((string) (new ReflectionClass(EnvelopedResource::class))->getFileName());
});
