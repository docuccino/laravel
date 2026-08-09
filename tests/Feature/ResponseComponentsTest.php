<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * Reusable response components (design §6 error-response chain / the Problem Details preset seam):
 * shared `components.responses` are hoisted once, referenced by `$ref` from many operations, roll
 * back with a failed route, and survive a warm cache hit — the same discipline schemas get (S5).
 *
 * An extension that references a shared response component (`$name`) from status 418 of every
 * operation; for `$throwOnUri` it additionally registers an orphan response and then explodes.
 */
function shareResponseExtension(string $name, ?string $throwOnUri = null): OperationExtension
{
    return new class($name, $throwOnUri) implements OperationExtension
    {
        public function __construct(private string $name, private ?string $throwOnUri) {}

        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            $ref = $context->components->referenceResponse($this->name, [
                'description' => 'A shared problem response',
                'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]],
            ]);
            $operation->response('418')->setRef($ref['$ref'], Contribution::attribute());

            if ($this->throwOnUri !== null && $context->route->uri === $this->throwOnUri) {
                $context->components->registerResponse('OrphanResponse', ['description' => 'Orphan']);

                throw new RuntimeException('boom after registering a response');
            }
        }
    };
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-respcache-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('hoists a shared response component once and resolves every $ref to it', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    Docuccino::extend(shareResponseExtension('SharedProblem'));

    $document = generateDocument()->document->toArray();

    expect($document['components']['responses']['SharedProblem']['description'] ?? null)
        ->toBe('A shared problem response');

    $refs = [];
    foreach ($document['paths'] as $item) {
        foreach ($item as $operation) {
            if (isset($operation['responses']['418']['$ref'])) {
                $refs[] = $operation['responses']['418']['$ref'];
            }
        }
    }

    // Many operations, one component — the $refs all resolve to the single hoisted response.
    expect($refs)->not->toBeEmpty()
        ->and(array_unique($refs))->toBe(['#/components/responses/SharedProblem']);
});

it('rolls back a response component registered by a route that then throws', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    Docuccino::extend(shareResponseExtension('SharedResponse', throwOnUri: '/api/ping'));

    $document = generateDocument()->document->toArray();

    // The healthy routes' shared response survives; the failed route's orphan left nothing behind.
    expect($document['components']['responses'] ?? [])->toHaveKey('SharedResponse')
        ->and($document['components']['responses'] ?? [])->not->toHaveKey('OrphanResponse');
});

it('restores a response component from a warm cache hit without touching the engine', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-respcache-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);
    Docuccino::extend(shareResponseExtension('SharedProblem'));

    $cold = generateDocument()->document->toArray();
    expect($cold['components']['responses'] ?? [])->toHaveKey('SharedProblem')
        ->and($engine->analyzeCount)->toBeGreaterThan(0);

    // Warm run: fragments are served from cache (the extension never re-runs), yet the referenced
    // response component is restored from the fragment and re-emitted.
    $engine->analyzeCount = 0;
    $warm = generateDocument()->document->toArray();

    expect($warm['components']['responses'] ?? [])->toHaveKey('SharedProblem')
        ->and($engine->analyzeCount)->toBe(0);
});
