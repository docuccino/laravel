<?php

declare(strict_types=1);

use Docuccino\Attributes\Group;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Attributes\AnonymousThrowController;
use Docuccino\Laravel\Tests\Fixtures\Attributes\InheritingController;
use Docuccino\Laravel\Tests\Fixtures\Attributes\MalformedAttributeController;
use Docuccino\Laravel\Tests\Fixtures\Attributes\MalformedClassAttributeController;
use Docuccino\Laravel\Tests\Fixtures\Attributes\OverridingController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Class-level attributes walk the controller's parents nearest-first — a `#[Group]` stated once on an
 * abstract base reaches every child, a child's own singleton beats the base's — and an attribute whose
 * arguments don't fit its constructor is reported instead of silently swallowed. The `@deprecated`
 * docblock tag marks the operation exactly as `#[DeprecatedOperation]` does.
 */
beforeEach(function (): void {
    $this->builtDocument = static function (callable $routes): array {
        $routes(app('router'));
        app()->instance(TypeEngine::class, WorkbenchEngine::make());

        $result = generateDocument();

        return [$result->document->toArray(), $result->diagnostics];
    };
});

it('inherits a base controller\'s class-level attributes', function (): void {
    [$document] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-inherit', [InheritingController::class, 'index']);
    });

    $operation = $document['paths']['/api/zz-attr-inherit']['get'];

    expect($operation['tags'])->toBe(['Legacy API'])
        ->and($operation['summary'])->toBe('A summary from the base controller');
});

it('lets the nearest declaration win: the child\'s singleton beats the base\'s, groups collect child-first', function (): void {
    [$document] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-override', [OverridingController::class, 'index']);
    });

    $operation = $document['paths']['/api/zz-attr-override']['get'];

    expect($operation['summary'])->toBe('A summary from the child controller')
        ->and($operation['tags'])->toBe(['Own Group', 'Legacy API']);
});

it('reports an attribute whose arguments do not fit, and keeps collecting the rest', function (): void {
    [$document, $diagnostics] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-malformed', [MalformedAttributeController::class, 'index']);
    });

    $operation = $document['paths']['/api/zz-attr-malformed']['get'];
    $reports = diagnosticsCoded($diagnostics, 'attribute.unreadable');

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->severity->value)->toBe('warning')
        ->and($reports[0]->message)->toContain('#[Group]')
        ->and($reports[0]->message)->toContain('could not be instantiated')
        ->and($reports[0]->help)->toContain('TypeError')
        ->and($reports[0]->routeSignature)->toBe('GET /api/zz-attr-malformed')
        ->and($operation['summary'])->toBe('Still documented');
});

it('names an anonymous thrower by where it stands, not by where the build machine keeps it', function (): void {
    // PHP allows `new` in an attribute's arguments, so what instantiating one throws is not limited to
    // the named errors PHP raises itself — and `::class` on an anonymous exception is the base name, a
    // NUL byte, then the ABSOLUTE file it was written in and a counter of the anonymous classes this
    // process declared first. Diagnostics are embedded in the document, so printing it raw puts the
    // build machine into the output and makes two runs over one tree disagree.
    [, $diagnostics] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-anon-throw', [AnonymousThrowController::class, 'index']);
    });

    $help = diagnosticsCoded($diagnostics, 'attribute.unreadable')[0]->help ?? '';

    expect($help)->toContain('RuntimeException@anonymous declared in ')
        // Relative to the nearest composer root, which is the resolver's documented degradation where
        // nothing handed it a base path — portable, and the same on every machine.
        ->toContain('tests/Fixtures/Attributes/ThrowsAnonymously.php:')
        // No NUL byte, no absolute prefix, and no process-order counter.
        ->and($help)->not->toContain("\0")
        ->and($help)->not->toContain(dirname(__DIR__, 4))
        ->and($help)->not->toMatch('/\$[0-9a-f]+\./');
});

it('names a closure route\'s site relatively, since a closure has no name but its file', function (): void {
    // An action's symbol falls back to the FILE where there is no class, so an ordinary closure route
    // names one ABSOLUTELY. The site goes in the message the way the cause goes in the help, and a
    // diagnostic is embedded in the document, so raw it puts the build machine into the output.
    [, $diagnostics] = ($this->builtDocument)(static function (Router $router): void {
        $router->get(
            'api/zz-attr-closure-malformed',
            /* @phpstan-ignore-next-line argument.type — the wrong argument type IS the fixture */
            #[Group(123)]
            static fn (): array => [],
        );
    });

    $message = diagnosticsCoded($diagnostics, 'attribute.unreadable')[0]->message ?? '';

    expect($message)->toContain('The #[Group] on tests/Feature/AttributeInheritanceTest.php::{closure} ')
        ->and($message)->not->toContain(dirname(__DIR__, 4));
});

it('reports a malformed CLASS-level attribute, naming the class it was written on', function (): void {
    [$document, $diagnostics] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-class-malformed', [MalformedClassAttributeController::class, 'index']);
    });

    $operation = $document['paths']['/api/zz-attr-class-malformed']['get'];
    $reports = diagnosticsCoded($diagnostics, 'attribute.unreadable');

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->severity->value)->toBe('warning')
        ->and($reports[0]->message)->toBe(sprintf(
            'The #[Group] on %s could not be instantiated and was ignored.',
            MalformedClassAttributeController::class,
        ))
        ->and($reports[0]->help)->toContain('TypeError')
        ->and($reports[0]->routeSignature)->toBe('GET /api/zz-attr-class-malformed')
        // The healthy class-level neighbour still applies.
        ->and($operation['summary'])->toBe('Still documented from the class');
});

it('marks an operation deprecated from the @deprecated docblock tag, publishing its reason', function (): void {
    [$document] = ($this->builtDocument)(static function (Router $router): void {
        $router->get('api/zz-attr-deprecated', [InheritingController::class, 'archived']);
        // The control: no tag, no flag.
        $router->get('api/zz-attr-live', [InheritingController::class, 'index']);
    });

    $deprecated = $document['paths']['/api/zz-attr-deprecated']['get'];
    $live = $document['paths']['/api/zz-attr-live']['get'];

    expect($deprecated['deprecated'] ?? null)->toBeTrue()
        // The tag's trailing text is the reason, and a summary-only docblock leaves it the description.
        ->and($deprecated['description'])->toBe('**Deprecated:** Superseded by the v2 listing.')
        ->and($live)->not->toHaveKey('deprecated')
        ->and($live)->not->toHaveKey('description');
});
