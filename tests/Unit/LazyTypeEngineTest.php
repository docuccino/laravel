<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use PhpParser\Node;

/**
 * The deferred engine: it boots exactly where it used to (before the first question) and not at all
 * when no question comes, which is every route on a fully warm fragment cache.
 */
it('does not build its engine until something asks it a question', function (): void {
    $built = 0;
    $engine = new LazyTypeEngine(function () use (&$built): TypeEngine {
        $built++;

        return new NullTypeEngine;
    }, NullTypeEngine::class);

    // Naming the engine is free; that is what lets the fragment cache key on it without booting it.
    expect($built)->toBe(0)
        ->and($engine->identity())->toBe(NullTypeEngine::class);

    $engine->classMetadata(new ClassRef('Workbench\\App\\Data\\FormData'));

    expect($built)->toBe(1);
});

it('builds its engine once and forwards every method to it', function (): void {
    $built = 0;
    $engine = new LazyTypeEngine(function () use (&$built): TypeEngine {
        $built++;

        return new StubTypeEngine;
    }, StubTypeEngine::class);

    $action = new ActionRef('/app/Controller.php', 'App\\Controller', 'index');
    $callable = new CallableRef('/app/Handler.php', 'App\\Handler', 'render');
    $visitor = new class implements TraceVisitor
    {
        public function enterNode(Node $node, TypeScope $scope): bool
        {
            return false;
        }
    };

    expect($engine->analyzeAction($action)->dependencyFiles)->toBe(['/app/Controller.php'])
        ->and($engine->analyzeCallable($callable)->dependencyFiles)->toBe(['/app/Handler.php'])
        ->and($engine->classMetadata(new ClassRef('App\\Data'))->fqcn)->toBe('App\\Data')
        ->and($engine->trace($action, $visitor)->dependencyFiles)->toBe(['/app/Controller.php'])
        ->and($built)->toBe(1);
});

it('names what it will build without building it, agreeing with the factory that would build it', function (string $mode, bool $installed, string $expected): void {
    $factory = new TypeEngineFactory(
        basePath: base_path(),
        tmpDir: storage_path('docuccino'),
        engine: new EnginePackage(static fn (string $class): bool => $installed),
    );

    expect($factory->engineIdentity(['mode' => $mode]))->toBe($expected);

    // Where the identity says nothing will be analysed, the factory really does resolve to nothing.
    if ($expected === NullTypeEngine::class) {
        expect($factory->make(['mode' => $mode]))->toBeInstanceOf(NullTypeEngine::class);
    }
})->with([
    'opted out of inference' => ['null', true, NullTypeEngine::class],
    'engine absent' => ['in-process', false, NullTypeEngine::class],
    'engine absent, mode unknown' => ['nonsense', false, NullTypeEngine::class],
    'engine installed' => ['in-process', true, EnginePackage::BUILDER],
    'engine installed, mode unknown (in-process is the default)' => ['nonsense', true, EnginePackage::BUILDER],
]);

it('defers the engine through the container binding the adapter ships', function (): void {
    // No stub is bound here, so this is the shipped wiring: resolving a TypeEngine hands back a
    // deferred one rather than a booted analyser.
    $engine = app(TypeEngine::class);

    expect($engine)->toBeInstanceOf(LazyTypeEngine::class);
    expect($engine->identity())->toBe(EnginePackage::BUILDER);
});
