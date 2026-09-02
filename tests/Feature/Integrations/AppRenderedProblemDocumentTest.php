<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * What an application answering RFC 9457 gets with nothing configured: the `application/problem+json`
 * media type and the members of its own body, both read out of its own renderer and published by a
 * document with `error_responses` at the shipped `default`.
 *
 * The whole error contract of such a document rides on this, so both halves are real. The recovered
 * response comes from the real engine reading the fixture app's own invokable renderer — arms that reach
 * their bodies through helper hops, which is how applications actually write one — and the document
 * around it is the full pipeline, with the error synthesized the way the framework produces it (a
 * route-model binding's 404, a validated request's 422) rather than scripted onto the operation.
 *
 * A body carrying `errors` under 422 and not under 404 is the other half: the per-status difference is a
 * fact about the two arms, so stating it needs no per-status view of one shared shape. The two tests
 * below are ONE guard on that, and each states both sides of it — a refinement that leaked `errors` onto
 * every arm is exactly what a pair of tests each asserting only its own arm's presence would miss.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The `JsonResponse<…>` the real engine recovers for one arm of the fixture app's own renderer. */
function appRenderedProblem(string $narrowType): DType
{
    $analysis = ActionAnalysis::fromArray(['returns' => FixtureRunner::analyzeCallable(
        'app/Exceptions/InvoiceProblemRenderer.php',
        'App\\Exceptions\\InvoiceProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: $narrowType,
    )['returns']]);

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;

    // The three facts the document is about to publish, proven present before it is asked to publish
    // them: a JsonResponse, a folded status, and the media type the renderer sends it as.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs[1] ?? null)->toBeInstanceOf(LiteralT::class)
        ->and($type->typeArgs[2] ?? null)->toEqual(new LiteralT('application/problem+json'));

    return $type;
}

/**
 * Document the workbench with `$render` registered on the booted handler, answering with the response the
 * real engine recovered for `$narrowType`.
 *
 * @return array<string, mixed>
 */
function documentWithAppRenderer(Closure $render, string $exceptionFqcn, string $narrowType): array
{
    $symbol = registerRenderCallback($render, $exceptionFqcn);

    app()->instance(TypeEngine::class, WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(appRenderedProblem($narrowType), new SourceLocation(''))]),
    ]));

    return generateDocument()->document->toArray();
}

it('publishes the media type and the body an application’s own renderer proved, with nothing configured', function (): void {
    // The route's 404 is the framework's to produce — an implicit model binding — and the application's to
    // ANSWER. With no preset declaring a contract in the abstract, the answer is the one the renderer
    // gives, so the document states problem+json and never the framework's `application/json` `{message}`.
    $document = documentWithAppRenderer(
        static fn (ModelNotFoundException $e) => response()->json([], 404),
        ModelNotFoundException::class,
        'App\\Exceptions\\InvoiceNotFoundException',
    );
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];
    $content = resolveResponse($document, $responses['404'])['content'] ?? [];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:inferred-handler')
        ->and($content)->toHaveKey('application/problem+json')
        ->and($content)->not->toHaveKey('application/json');

    // The members are the application's own, recovered through two helper hops — never a shape this
    // package holds a copy of, and never the framework's `{message}`.
    $schema = resolveSchema($document, $content['application/problem+json']['schema'] ?? []);
    expect($schema['type'] ?? null)->toBe('object')
        ->and(array_keys($schema['properties'] ?? []))->toContain('type', 'title', 'status', 'detail')
        ->and($schema['properties'] ?? [])->not->toHaveKey('message')
        // The 404 half of the per-status difference: this arm builds no `errors`, so this body states
        // none. Asserted here rather than only where 422 states one, because `toContain()` is not
        // exclusive and a refinement leaking the member onto every arm would satisfy that side alone.
        ->and($schema['properties'] ?? [])->not->toHaveKey('errors');
})->group('fixture');

it('carries the member one status has and the others do not, because the arms differ', function (): void {
    // 422 is the status a shared error shape cannot state honestly: it carries `errors` and the rest do
    // not. Read per arm, that needs no grafting — the validation arm builds a body with `errors` in it.
    $document = documentWithAppRenderer(
        static fn (ValidationException $e) => response()->json([], 422),
        ValidationException::class,
        ValidationException::class,
    );
    $content = resolveResponse($document, $document['paths']['/api/tickets']['post']['responses']['422'])['content'] ?? [];

    expect($content)->toHaveKey('application/problem+json');

    $schema = resolveSchema($document, $content['application/problem+json']['schema'] ?? []);
    expect(array_keys($schema['properties'] ?? []))->toContain('errors')
        // And everything the 404 arm states as well, which is what makes `errors` the DIFFERENCE between
        // the two rather than this arm simply being read better.
        ->and(array_keys($schema['properties'] ?? []))->toContain('type', 'title', 'status', 'detail')
        // A list of pointer objects, because that is what the arm builds — not a shape chosen by config.
        ->and($schema['properties']['errors']['type'] ?? null)->toBe('array')
        ->and(array_keys($schema['properties']['errors']['items']['properties'] ?? []))->toContain('pointer', 'detail');
})->group('fixture');
