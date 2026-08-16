<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Router;

/**
 * Declared error component names, through the whole adapter.
 *
 * `Error404` is what a generated client's type ends up called, and the exception that produced it is a
 * bad name for it: three exceptions can render one body and one exception can render two, so naming the
 * component after either would let deleting an unrelated route rename a type. A producer that speaks for
 * ONE kind of error declares the name instead, and the name is then a function of the declarer alone.
 */

/**
 * An extension that declares `$name` on status `$status` of every operation it sees, and gives it a
 * body — a non-mapper producer, which is the case the claim living on the response rather than on
 * `ExceptionToResponse` exists to serve.
 */
function declaringExtension(string $status, string $name, string $property = 'reason'): OperationExtension
{
    return new class($status, $name, $property) implements OperationExtension
    {
        public function __construct(
            private readonly string $status,
            private readonly string $name,
            private readonly string $property,
        ) {}

        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            $by = Contribution::attribute();
            $response = $operation->response($this->status);

            $response->claimComponentName($this->name, $by);
            $response->setDescription('Declared', $by);
            $response->content('application/json')->set('type', 'object', $by);
            $response->content('application/json')->set('properties', [$this->property => ['type' => 'string']], $by);
        }
    };
}

/**
 * The error components a document published, name → the bytes under it, for both buckets.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array<string, mixed>>
 */
function errorComponents(array $document, string $bucket = 'schemas'): array
{
    return array_filter(
        $document['components'][$bucket] ?? [],
        static fn (string $name): bool => in_array($name, ['NotFound', 'UnprocessableEntity', 'Unauthorized', 'Forbidden', 'TooManyRequests'], true)
            || str_starts_with($name, 'Error'),
        ARRAY_FILTER_USE_KEY,
    );
}

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
});

it('publishes the framework\'s own errors under the names it calls them', function (): void {
    // The default output. Laravel's 404 and 422 are the two the workbench states more than once, and
    // both come out named after the error rather than after the number.
    bindStubEngine();
    $document = generateDocument()->document->toArray();

    expect(array_keys(errorComponents($document)))->toBe(['NotFound', 'UnprocessableEntity'])
        ->and(array_keys(errorComponents($document, 'responses')))->toBe(['NotFound', 'UnprocessableEntity'])
        ->and($document['components']['responses']['NotFound']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/NotFound'])
        ->and($document['paths']['/api/forms/{form}']['get']['responses']['404']['$ref'])
        ->toBe('#/components/responses/NotFound');
});

it('lets any producer of an error response declare its name, not just an exception mapper', function (): void {
    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing'));

    $document = generateDocument()->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('Brewing')
        ->and($document['components']['responses'])->toHaveKey('Brewing')
        ->and($document['components']['schemas'])->not->toHaveKey('Error418');
});

it('gives two declarers that spell the same body a named component each', function (): void {
    // Two kinds of error that happen to render identically are still two kinds of error. Keyed on the
    // body alone they would be one component with one of the two names; keyed on the declaration they
    // are two, and neither name depends on the other existing.
    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing'));
    Docuccino::extend(declaringExtension('419', 'Steeping'));

    $document = generateDocument()->document->toArray();

    expect($document['components']['schemas'])->toHaveKeys(['Brewing', 'Steeping'])
        ->and($document['components']['schemas']['Brewing']['properties'])
        ->toBe($document['components']['schemas']['Steeping']['properties'])
        ->and($document['components']['schemas']['Brewing']['x-docuccino']['id'])
        ->not->toBe($document['components']['schemas']['Steeping']['x-docuccino']['id']);
});

it('does not rename an existing component when a declaring producer is added', function (): void {
    // Locality, the invariant the whole design is for: a part of the application learning to name its
    // own error must leave every other name in the document byte-identical.
    bindStubEngine();
    $before = generateDocument()->document->toArray();

    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing'));
    $after = generateDocument()->document->toArray();

    expect(array_keys(errorComponents($after)))->toBe(array_keys(errorComponents($before)))
        ->and(errorComponents($after))->toBe(errorComponents($before))
        ->and(errorComponents($after, 'responses'))->toBe(errorComponents($before, 'responses'))
        // …and the new one really did arrive, so the row above is not equality between two nothings.
        ->and($after['components']['schemas'])->toHaveKey('Brewing');
});

it('does not rename a declared component when an unrelated route is added', function (): void {
    // The other direction: the declared name is a function of the declarer, so a route that sorts first
    // and states the same declared error cannot move it.
    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing'));
    $before = generateDocument()->document->toArray();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/aaa-unrelated', [ErrorsController::class, 'unrelated']);

    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing'));
    $after = generateDocument()->document->toArray();

    expect($after['components']['schemas']['Brewing'])->toBe($before['components']['schemas']['Brewing'])
        ->and($after['components']['responses']['Brewing'])->toBe($before['components']['responses']['Brewing'])
        ->and($after['paths']['/api/aaa-unrelated']['get']['responses']['418']['$ref'])
        ->toBe('#/components/responses/Brewing');
});

/**
 * Declares a name on one ROUTE's response and touches nothing else — the half of a mixed document that
 * names its error, with the other half left exactly as whatever produced it.
 */
function claimingOn(string $uri, string $status, string $name): OperationExtension
{
    return new class($uri, $status, $name) implements OperationExtension
    {
        public function __construct(
            private readonly string $uri,
            private readonly string $status,
            private readonly string $name,
        ) {}

        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            if ($context->route->uri === '/'.ltrim($this->uri, '/') && $operation->hasResponse($this->status)) {
                $operation->response($this->status)->claimComponentName($this->name, Contribution::attribute());
            }
        }
    };
}

it('shares a body one route names and another does not', function (): void {
    // The mixed case a tiered chain produces in a real application: the tier that answers first can
    // recover a body without naming it while a later tier names an identical one. What repeats decides
    // whether a body is hoisted, so both routes still get a shared component — and the route that
    // declared nothing emits the same bytes it did before the other one learned to.
    $routes = static function (Router $router): void {
        $router->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
        $router->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
    };

    bindStubEngine();
    $routes(app('router'));
    $before = generateDocument()->document->toArray();

    bindStubEngine();
    $routes(app('router'));
    Docuccino::extend(claimingOn('api/zz-blocked', '403', 'Blocked'));
    $after = generateDocument()->document->toArray();

    expect($before['paths']['/api/zz-blocked']['get']['responses']['403']['$ref'])
        ->toBe('#/components/responses/Error403')
        // The declaring route moved to its own name…
        ->and($after['paths']['/api/zz-blocked']['get']['responses']['403']['$ref'])
        ->toBe('#/components/responses/Blocked')
        // …and the one that declared nothing did not move at all, component and reference alike.
        ->and($after['paths']['/api/zz-blocked-again'])->toBe($before['paths']['/api/zz-blocked-again'])
        ->and($after['components']['responses']['Error403'])->toBe($before['components']['responses']['Error403'])
        ->and($after['components']['schemas']['Error403'])->toBe($before['components']['schemas']['Error403']);
});

it('retires a declared name two different bodies contest, and warns', function (): void {
    // A contest is a contest whoever asked: both climb, and the warning names the claimants rather than
    // letting one silently take a name that used to mean the other.
    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Brewing', 'reason'));
    Docuccino::extend(declaringExtension('419', 'Brewing', 'detail'));

    $result = generateDocument();
    $document = $result->document->toArray();

    $names = array_values(array_filter(
        array_map(strval(...), array_keys($document['components']['schemas'])),
        static fn (string $name): bool => str_starts_with($name, 'Brewing'),
    ));

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('Brewing')
        ->and($names)->each->toMatch('/^Brewing_[a-z2-7]{8}$/')
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-collision'))->not->toBeEmpty();
});

it('refuses a name no component key could carry at the write, and never publishes it', function (): void {
    // The draft enforces the contract, so a name a `$ref` could not point at is read as no declaration
    // at all: the body falls back to its status and nothing anywhere in the document — component key or
    // `x-docuccino` fact — carries the string. The hoist's `components.name-invalid` still exists for
    // the one source the draft cannot police, a document that already states the fact (an overlay).
    bindStubEngine();
    Docuccino::extend(declaringExtension('418', 'Not Brewing!'));

    $result = generateDocument();
    $document = $result->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('Error418')
        ->and($document['components']['schemas'])->not->toHaveKey('Not Brewing!')
        ->and(json_encode($document))->not->toContain('Not Brewing!');
});

it('publishes the same bytes and the same diagnostics on a warm fragment-cache build', function (): void {
    // The declaration travels on the operation fragment or not at all: a warm hit re-runs no route, so a
    // claim that lived anywhere else would be lost and every declared name would come back `Error<status>`.
    $routes = static function (Router $router): void {
        $router->get('api/zz-denied', [ErrorsController::class, 'denied']);
        $router->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
    };

    Docuccino::extend(declaringExtension('418', 'Brewing'));

    $warm = assertWarmEqualsCold($routes, $routes);

    expect($warm->document->toArray()['components']['schemas'])->toHaveKey('Brewing');
});

/**
 * An application's own mapper for one exception, declaring its own name — the path an app takes to
 * override a built-in outright. Unannotated, so it sorts at `Priorities::DEFAULT`: ahead of the
 * framework-errors tier (`LATE`) and the terminal fallback (`LAST`), which is what makes the override
 * work without an `#[ExtensionOrder]` of its own.
 */
function appExceptionMapper(string $fqcn, string $status, string $name): ExceptionToResponse
{
    return new class($fqcn, $status, $name) implements ExceptionToResponse
    {
        public function __construct(
            private readonly string $fqcn,
            private readonly string $status,
            private readonly string $name,
        ) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return is_a($exception->exceptionFqcn, $this->fqcn, true);
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $by = Contribution::integration('acme');

            $draft = new ResponseDraft($this->status);
            $draft->claimComponentName($this->name, $by);
            $draft->setDescription('Not Found', $by);
            $draft->content('application/json')->set('type', 'object', $by);
            $draft->content('application/json')->set('properties', ['detail' => ['type' => 'string']], $by);

            return $draft;
        }
    };
}

/** An extension that renames whatever component a status already claimed, without touching its body. */
function renamingExtension(string $status, string $name, Contribution $by): OperationExtension
{
    return new class($status, $name, $by) implements OperationExtension
    {
        public function __construct(
            private readonly string $status,
            private readonly string $name,
            private readonly Contribution $by,
        ) {}

        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            if ($operation->hasResponse($this->status)) {
                $operation->response($this->status)->claimComponentName($this->name, $this->by);
            }
        }
    };
}

it('publishes an application\'s own mapper under the name that mapper declares', function (): void {
    // Adding a mapper. The claim rides the response the mapper builds, so a third-party mapper names
    // its component with exactly the call a built-in uses — there is no separate registration.
    bindStubEngine();
    Docuccino::extend(appExceptionMapper(ModelNotFoundException::class, '404', 'ResourceMissing'));

    $document = generateDocument()->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($document['components']['responses'])->toHaveKey('ResourceMissing')
        // The built-in tier never answered, so its name is nowhere in the document.
        ->and($document['components']['schemas'])->not->toHaveKey('NotFound')
        ->and($document['paths']['/api/forms/{form}']['get']['responses']['404']['$ref'])
        ->toBe('#/components/responses/ResourceMissing');
});

it('renames a built-in\'s component without touching the body it named', function (): void {
    // Overriding the name and nothing else. The built-in tiers claim at `integration`, the layer an
    // application extension writes at too — so the tie breaks on specificity, exactly as it does for
    // every other field. What comes back is the same body under a name the application chose.
    bindStubEngine();
    $before = generateDocument()->document->toArray();

    bindStubEngine();
    Docuccino::extend(renamingExtension('404', 'ResourceMissing', Contribution::integration('acme', specificity: 1)));
    $after = generateDocument()->document->toArray();

    $strip = static function (array $schema): array {
        unset($schema['x-docuccino']);

        return $schema;
    };

    expect($after['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($after['components']['schemas'])->not->toHaveKey('NotFound')
        ->and($strip($after['components']['schemas']['ResourceMissing']))
        ->toBe($strip($before['components']['schemas']['NotFound']));
});

it('shadows an application claim that only ties with the built-in', function (): void {
    // The other direction, and the reason specificity is the documented answer: an equal contribution
    // changes nothing, so a rename that quietly did not happen is not a thing an author can hit
    // without the ladder telling them why.
    bindStubEngine();
    Docuccino::extend(renamingExtension('404', 'Shadowed', Contribution::integration('acme')));

    $document = generateDocument()->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('NotFound')
        ->and($document['components']['schemas'])->not->toHaveKey('Shadowed');
});
