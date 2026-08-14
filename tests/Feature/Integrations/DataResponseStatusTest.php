<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataResponseStatus;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AccountData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CreatedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NoStatusData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NotAData;

/**
 * calculateResponseStatus() folding: a Data class overriding the method re-homes the inferred 200 to its
 * folded constant status — or several, when a conditional/ternary's arms all fold, each documented. A
 * non-override is a no-op; a genuinely computed status degrades to 200 with a diagnostic. Detecting the
 * override is real reflection; the folded return type is scripted, since the engine's literal-int
 * inference (including class/enum constants) is proven separately.
 */
function statusContext(StubTypeEngine $engine, string $method = 'POST'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$method], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );
}

/** @param list<DType> $returnTypes */
function statusEngine(string $fqcn, array $returnTypes): StubTypeEngine
{
    $loc = new SourceLocation('');

    return new StubTypeEngine(analyses: [
        $fqcn.'::calculateResponseStatus' => new ActionAnalysis(
            returns: array_map(static fn ($t): ReturnSite => new ReturnSite($t, $loc), $returnTypes),
        ),
    ]);
}

it('folds a single constant status override to the response status', function (): void {
    $context = statusContext(statusEngine(CreatedData::class, [new LiteralT(201)]));

    expect((new DataResponseStatus)->resolveStatuses($context, CreatedData::class))->toBe([201])
        ->and($context->components->diagnostics())->toBe([]);
});

it('folds a conditional/ternary whose arms all fold to every constant status', function (array $returnTypes): void {
    // A ternary `$x ? 201 : 200` translates to one return site typed UnionT([LiteralT, LiteralT]);
    // an if/return conditional yields two LiteralT sites. Both fold to the full status set, sorted.
    $context = statusContext(statusEngine(CreatedData::class, $returnTypes));

    expect((new DataResponseStatus)->resolveStatuses($context, CreatedData::class))->toBe([200, 201])
        ->and($context->components->diagnostics())->toBe([]);
})->with([
    'a ternary (union of literals in one return site)' => [[UnionT::of([new LiteralT(201), new LiteralT(200)])]],
    'two if/return sites' => [[new LiteralT(201), new LiteralT(200)]],
]);

it('documents a POST returning a plain Data class as 201, from spatie\'s inherited default', function (): void {
    // AccountData doesn't override calculateResponseStatus, so the trait's own body applies:
    // `$request->isMethod(POST) ? 201 : 200`. Reading only overrides used to leave every create endpoint
    // confidently documented as 200.
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, AccountData::class))->toBe([201])
        ->and($context->components->diagnostics())->toBe([]);
});

it('stays quiet for a non-POST Data class, whose inherited default is already the documented 200', function (string $method): void {
    $context = statusContext(new StubTypeEngine, $method);

    expect((new DataResponseStatus)->resolveStatuses($context, AccountData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);

it('prefers a real override to the inherited POST default', function (): void {
    // CreatedData overrides the method, so the override is folded and the inherited 201 never applies —
    // an override returning 200 on a POST must document 200, not be overruled by the base default.
    $context = statusContext(statusEngine(CreatedData::class, [new LiteralT(200)]));

    expect((new DataResponseStatus)->resolveStatuses($context, CreatedData::class))->toBe([200]);
});

it('degrades a genuinely computed status to 200 with a diagnostic', function (array $returnTypes): void {
    $context = statusContext(statusEngine(CreatedData::class, $returnTypes));

    expect((new DataResponseStatus)->resolveStatuses($context, CreatedData::class))->toBe([]);

    $diagnostics = $context->components->diagnostics();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('spatie-data.response-status-unresolved');
})->with([
    'a widened (non-literal) int status' => [[ScalarT::int()]],
    'a foldable arm mixed with a computed arm' => [[new LiteralT(201), ScalarT::int()]],
    // A non-int literal isn't a status: string/bool/float literals degrade rather than being coerced
    // (foldIntLiterals' is_int guard on the single-literal arm).
    'a non-int string literal' => [[new LiteralT('201')]],
    'a non-int bool literal' => [[new LiteralT(true)]],
    // …and the same guard inside a union: one non-int member disqualifies the whole return site, rather
    // than documenting the int members and silently dropping the rest.
    'a union mixing an int literal with a string literal' => [[UnionT::of([new LiteralT(201), new LiteralT('created')])]],
    'a union mixing an int literal with a widened int' => [[UnionT::of([new LiteralT(201), ScalarT::int()])]],
    // A non-literal, non-union type entirely (the final `return null` fall-through).
    'a class-typed return' => [[new ClassT('Illuminate\\Http\\JsonResponse')]],
]);

it('is inert for a class that is not a spatie Data class at all (no diagnostic)', function (): void {
    // The ResponsableData-contract guard comes first: a plain class isn't this resolver's business even if
    // it happens to declare calculateResponseStatus() in its own file, so no statuses and no diagnostic.
    $context = statusContext(statusEngine(NotAData::class, [new LiteralT(201)]));

    expect((new DataResponseStatus)->resolveStatuses($context, NotAData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});

it('is inert for a non-existent class (nothing to reflect)', function (): void {
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, 'App\\Data\\NoSuchData'))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});

it('assumes no default for a Data class that renders itself without spatie\'s concern', function (): void {
    // NoStatusData satisfies Contracts\BaseData but neither the response contract nor the concern, so
    // there is no vendor default to inherit — a hand-rolled renderer's status is its own business, and
    // guessing 201 on a POST would be inventing one.
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, NoStatusData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});
