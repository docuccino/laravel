<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchOrigin;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchProblem;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchProblemRenderer;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchRefusedException;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\BatchVerified;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * An error example fills a member the render path could not read from the schema beside it, so the
 * example stays a valid instance of that schema. A member typed as an INTERSECTION is described by a
 * CONJUNCTION — `allOf` of its converted halves — and a conjunction is the one composition a body member
 * really arrives as, since `IntersectionTypeToSchema` is what writes it.
 *
 * Which makes this the whole subject here: `"string"` is not a vaguer illustration of a member the
 * document says satisfies two object shapes at once, it is a value BOTH halves reject — so the build's
 * own example lint reports it on every run, against an example its reader never wrote and cannot
 * correct. The illustration owed is one instance of the conjunction, which is the object carrying every
 * member the branches between them require.
 *
 * The carrier is a plain readonly class, which is what a PHP intersection can actually be declared on: a
 * spatie `Data` class resolves each property's type to hydrate it, and it has no reading for an
 * intersection, so the schema-stated-fill family next door could not carry this member honestly.
 */

/** The two endpoints one refused batch answers for. */
function composedRoutes(Router $router): void
{
    foreach (['submit', 'replay'] as $action) {
        $router->get('api/batch-'.$action, [BatchController::class, $action]);
    }
}

/**
 * The engine as {@see BatchProblemRenderer} scripts it: the constructed `BatchProblem` at 422 under
 * `application/problem+json`, with the three words the arm writes out folded and the origin it asks the
 * failure for left unread.
 *
 * The renderer registers ONCE per application, however many builds a test runs: the handler outlives a
 * build, and re-registering would change what the next build's descriptor cache is keyed on.
 */
function composedEngine(): TypeEngine
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $renderer = new BatchProblemRenderer;

    if (! app()->bound('tests.batch-renderer')) {
        app()->instance('tests.batch-renderer', true);
        $handler->renderable($renderer);
    }

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $location = new SourceLocation('');

    $symbol = (new CallableRef(
        (string) $function->getFileName(),
        $renderer::class,
        $function->getName(),
        0,
        $function->getParameters()[0]->getName(),
        BatchRefusedException::class,
    ))->symbol();

    $callables = [$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new ClassT(BatchProblem::class),
            new LiteralT(422),
            new LiteralT('application/problem+json'),
            new ArrayShapeT([
                new ArrayShapeField('type', new LiteralT('https://example.com/problems/batch-refused')),
                new ArrayShapeField('title', new LiteralT('Unprocessable Content')),
                new ArrayShapeField('status', new LiteralT(422)),
                new ArrayShapeField('origin', new UnknownT('constructor argument not folded')),
            ]),
        ]),
        $location,
    )])];

    $analyses = [];
    foreach (['submit', 'replay'] as $action) {
        $analyses[BatchController::class.'::'.$action] = new ActionAnalysis(
            returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('ok', ScalarT::bool())]), $location)],
            throws: [new ThrownException(BatchRefusedException::class, 422, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
        );
    }

    // The declared types, as an engine reports them: the property is the intersection its declaration
    // states, the base carries the two members, and the marker carries none.
    return WorkbenchEngine::make($callables, [
        BatchProblem::class => new ClassMetadata(BatchProblem::class, [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('origin', new IntersectionT([new ClassT(BatchOrigin::class), new ClassT(BatchVerified::class)])),
        ]),
        BatchOrigin::class => new ClassMetadata(BatchOrigin::class, [
            new PropertyMetadata('actor', ScalarT::string()),
            new PropertyMetadata('attempt', ScalarT::int()),
        ]),
        BatchVerified::class => new ClassMetadata(BatchVerified::class, []),
    ], $analyses);
}

/**
 * The build the two batch routes produce, alone — diagnostics included, since the example lint's silence
 * is half of what this family proves.
 */
function composedResult(): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    composedRoutes($router);

    app()->instance(TypeEngine::class, composedEngine());

    return generateDocument();
}

/**
 * The 422 media type a batch route documents, read through whatever component it ended up in.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function composedMediaAt(array $document, string $action): array
{
    $response = $document['paths']['/api/batch-'.$action]['get']['responses']['422'] ?? [];

    $ref = is_array($response) ? ($response['$ref'] ?? null) : null;
    if (is_string($ref)) {
        $response = $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] ?? [];
    }

    $media = is_array($response) ? ($response['content']['application/problem+json'] ?? []) : [];

    return is_array($media) ? $media : [];
}

it('describes an intersection-typed member as a conjunction of its halves', function (): void {
    // The premise, asserted before the fill is: the member really does reach the document as an `allOf`
    // of two shapes, so a reader of the schema is told the value satisfies both. Without this row the
    // next assertion could pass over a member whose schema said nothing at all.
    $document = composedResult()->document->toArray();

    expect($document['components']['schemas']['BatchProblem']['properties']['origin'])->toBe([
        'allOf' => [
            ['$ref' => '#/components/schemas/BatchOrigin'],
            // The marker earns no component, because a type with no readable members describes nothing
            // but object-ness — which makes it the branch that must contribute NOTHING to the
            // illustration while still being a branch the value has to satisfy.
            ['type' => 'object'],
        ],
    ])->and($document['components']['schemas']['BatchOrigin']['required'])->toBe(['actor', 'attempt']);
});

it('illustrates a composed member with an instance of the whole conjunction', function (): void {
    // The contract, and it belongs to the SCHEMA rather than to this tier: an example is an instance of
    // the schema beside it. A conjunction is satisfied only by a value satisfying every branch, so the
    // illustration is the object carrying what the branches between them require — `"string"` satisfies
    // neither half, and a member the build read nothing about is exactly the one a consumer copies.
    $media = composedMediaAt(composedResult()->document->toArray(), 'submit');

    expect($media['example'])->toBe([
        'type' => 'https://example.com/problems/batch-refused',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'origin' => ['actor' => 'string', 'attempt' => 0],
    ]);
});

it('still records a composed fill as a member nothing read', function (): void {
    // The half a better fill could quietly lose. The object above is assembled from the schema and from
    // nothing the code said, so it stays in the record the illustration collapse reads: a value that
    // merely READS like one the server sends must not start counting as one the build proved, or the
    // collapse would drop a rival illustration that had actually proved it.
    $document = composedResult()->document->toArray();
    $facts = $document['paths']['/api/batch-submit']['get']['responses']['422']['x-docuccino']['facts'] ?? [];

    expect($facts)->toBe(['examplePlaceholders' => ['application/problem+json' => ['origin']]]);
});

it('publishes a composed example no build-time lint can fault', function (): void {
    // The build holds every published example to the schema beside it, which is the whole reason the
    // fill has to read the conjunction: an `allOf` left unreduced was illustrated `"string"`, and the
    // resulting warning named an example its reader never wrote and could not correct.
    $result = composedResult();
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);
    $document = $result->document->toArray();

    expect($codes)->not->toContain('lint.example-mismatch')
        ->and($codes)->not->toContain('lint.example-uncheckable')
        // Anti-vacuity: there really is a filled example here, under a schema that constrains the member
        // it filled — so the silence above is a clean audit and not an empty one.
        ->and($document['components']['schemas']['BatchProblem']['properties']['origin'])->toHaveKey('allOf')
        ->and(composedMediaAt($document, 'submit')['example'])->toHaveKey('origin');
});

it('publishes the same bytes on a warm fragment-cache build', function (): void {
    $warm = assertWarmEqualsCold(composedRoutes(...), composedRoutes(...), composedEngine(...));

    // Byte-locked, because no golden in the corpus could reach this: every composed member in every one
    // of them sits somewhere no example is filled, and the family whose subject IS the schema-stated
    // fill carries its problem document on a spatie `Data` class, which cannot declare an intersection.
    assertGolden('workbench-composed-member-fill.uir.json', (new UirEmitter)->emit($warm->document));
});
