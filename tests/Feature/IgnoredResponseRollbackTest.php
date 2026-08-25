<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DeclaredErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ThingMissingException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Where `IgnoredResponses::mapThrow()` draws its rollback line.
 *
 * Mapping a throw is a READ there: a producer asks what the response WOULD be so it can see the status
 * the mapper landed on, and if the route drops that status the registry rolls back — so a body converted
 * only to be discarded hoists no component and raises no diagnostic about a body nobody will see.
 *
 * The line was in the wrong place in both directions. A note the tier wrote on the ROUTE is not in the
 * registry, so a summary asking the author to fix a response the document does not publish survived the
 * rollback — a diagnostic firing exactly where the reader cannot act. And a refused component NAME is a
 * fact about a line of source, not about the discarded body: the attribute does nothing on every route
 * that reads it, and the rollback was the reason nobody was told.
 */

/** The one action these rows use — it carries `#[IgnoreResponse(409)]`, so a mapped 409 is dropped. */
const ROLLBACK_ACTION = DeclaredErrorsController::class.'::twentySecond';

/**
 * A render path too dynamic to fold: a `JsonResponse` was recovered and neither its body nor its status
 * came back, which is the one case the tier defers on loudly — it is not a framework delegation, and it
 * has nothing the later tiers do not.
 */
function rollbackUnfoldable(?ComponentDeclaration $component = null): ActionAnalysis
{
    return new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new UnknownT('the body is built in a loop'),
            new UnknownT('the status comes from a variable'),
        ]),
        new SourceLocation(''),
        $component,
    )]);
}

/** A body that folds, under whatever name the render method declared. */
function rollbackFoldable(?ComponentDeclaration $component): ActionAnalysis
{
    return new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new ArrayShapeT([new ArrayShapeField('detail', ScalarT::string())]),
            new LiteralT(409),
        ]),
        new SourceLocation(''),
        $component,
    )]);
}

/** A declaration as the engine reports one, on the render method that answered. */
function rollbackDeclaration(string $name): ComponentDeclaration
{
    return new ComponentDeclaration(
        $name,
        'App\\Exceptions\\PortalRenderer::renderRejection',
        new SourceLocation('/app/Exceptions/PortalRenderer.php', 12),
    );
}

/**
 * A mapper the inferred-handler tier is asked BEFORE, so it answers only where that tier declined —
 * which is the shape of every later error tier, and the case where a discarded mapping is the one whose
 * status gets dropped.
 */
function rollbackTrailingMapper(): ExceptionToResponse
{
    return new class implements ExceptionToResponse
    {
        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return is_a($exception->exceptionFqcn, ThingMissingException::class, true);
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $by = Contribution::integration('acme');

            $draft = new ResponseDraft('409');
            $draft->claimComponentName('TrailingConflict', $by);
            $draft->setDescription('Conflict', $by);
            $draft->content('application/json')->set('type', 'object', $by);

            return $draft;
        }
    };
}

/** Register the ignoring route and script the throw plus what analysing its render callback recovers. */
function rollbackBuild(ActionAnalysis $rendered): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-rollback', [DeclaredErrorsController::class, 'twentySecond']);

    // Typed to the one exception these rows script, so no other workbench route's throws reach this tier
    // and the deferral summary names this callback for exactly one reason or for none.
    $callable = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$callable => $rendered],
        analysisOverrides: [ROLLBACK_ACTION => new ActionAnalysis(throws: [new ThrownException(
            ThingMissingException::class,
            409,
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        )])],
    ));

    return generateDocument();
}

it('publishes no response at the status the route drops, and hoists nothing for it', function (): void {
    // The baseline the rollback exists for, and what every row below is measured against.
    $result = rollbackBuild(rollbackFoldable(rollbackDeclaration('RenderedRejection')));
    $document = $result->document->toArray();

    expect($document['paths']['/api/zz-rollback']['get']['responses'] ?? [])->not->toHaveKey('409')
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('RenderedRejection');
});

it('asks nobody to fix a response the document does not publish', function (): void {
    // The tier could not fold the body, so it noted the deferral for one summary per callback — and the
    // note rides the ROUTE rather than the registry, so the rollback that discarded the response left it
    // standing. The author is then told to go and make a response foldable that they had asked for by
    // name to be dropped: a diagnostic firing where there is nothing to do.
    $result = rollbackBuild(rollbackUnfoldable());

    expect(diagnosticsCoded($result->diagnostics, 'inferred-handler.too-dynamic'))->toBe([]);
});

it('still says a render callback would not fold where the response IS published', function (): void {
    // The other half of the population: the summary is the whole point of the deferral note, so a
    // rollback that silenced it everywhere would be the fix's own failure mode.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-rollback-published', [DeclaredErrorsController::class, 'first']);

    // Typed to the one exception these rows script, so no other workbench route's throws reach this tier
    // and the deferral summary names this callback for exactly one reason or for none.
    $callable = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$callable => rollbackUnfoldable()],
        analysisOverrides: [DeclaredErrorsController::class.'::first' => new ActionAnalysis(throws: [new ThrownException(
            ThingMissingException::class,
            409,
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        )])],
    ));

    expect(diagnosticsCoded(generateDocument()->diagnostics, 'inferred-handler.too-dynamic'))->toHaveCount(1);
});

it('says nothing about a refused name on the one route that drops the response it would have named', function (): void {
    // Silent ON PURPOSE, and not because the rollback happens to reach it. The report's own sentence ends
    // "…and the response keeps the name it would have had", which is false where there is no response —
    // and the class-anchored half of the same report already declines for a dropped status, by an explicit
    // `continue` rather than by any rollback. Publishing here would ask an author to fix a name nobody
    // will read, on the one route that said so by name; the row below is where it is worth saying.
    $result = rollbackBuild(rollbackFoldable(rollbackDeclaration('Not Found!')));

    expect(diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid'))->toBe([])
        ->and($result->document->toArray()['paths']['/api/zz-rollback']['get']['responses'] ?? [])->not->toHaveKey('409');
});

it('refuses a declared name wherever a response at that status IS published', function (): void {
    // The other half of that population, and the whole reason the report exists: the same illegal name on
    // a route that publishes the status reaches the author, so an attribute doing nothing is never silent
    // everywhere — only on the routes that asked for the response to be dropped.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-rollback-named', [DeclaredErrorsController::class, 'first']);

    $callable = registerRenderCallback(
        static fn (ThingMissingException $e) => response()->json([], 409),
        ThingMissingException::class,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$callable => rollbackFoldable(rollbackDeclaration('Not Found!'))],
        analysisOverrides: [DeclaredErrorsController::class.'::first' => new ActionAnalysis(throws: [new ThrownException(
            ThingMissingException::class,
            409,
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        )])],
    ));

    $refused = diagnosticsCoded(generateDocument()->diagnostics, 'attribute.error-component-invalid');

    expect($refused)->toHaveCount(1)
        ->and($refused[0]->message)->toContain('Not Found!');
});

it('leaves a discarded mapping from a later tier hoisting nothing either', function (): void {
    // The rollback covers whichever mapper answered, not just the first: a trailing tier's component is
    // as orphaned as the inferred handler's would be.
    Docuccino::extend(rollbackTrailingMapper());

    $document = rollbackBuild(rollbackUnfoldable())->document->toArray();

    expect($document['paths']['/api/zz-rollback']['get']['responses'] ?? [])->not->toHaveKey('409')
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('TrailingConflict')
        ->and($document['components']['responses'] ?? [])->not->toHaveKey('TrailingConflict');
});
