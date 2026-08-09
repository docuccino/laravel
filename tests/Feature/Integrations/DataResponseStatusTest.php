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
function statusContext(StubTypeEngine $engine): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
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

it('leaves a plain Data class (no override) at the inferred status with no diagnostic', function (): void {
    // AccountData doesn't override calculateResponseStatus: the trait default reports the vendor file, so
    // it isn't treated as a documentable override.
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, AccountData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
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
    // The isData() guard comes first: a plain class isn't this resolver's business even if it happens to
    // declare calculateResponseStatus() in its own file, so it yields no statuses and no diagnostic.
    $context = statusContext(statusEngine(NotAData::class, [new LiteralT(201)]));

    expect((new DataResponseStatus)->resolveStatuses($context, NotAData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});

it('is inert for a non-existent class (nothing to reflect)', function (): void {
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, 'App\\Data\\NoSuchData'))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});

it('is inert for a Data class whose calculateResponseStatus is absent entirely (hasMethod false)', function (): void {
    // NoStatusData is a Data class that doesn't inherit the ResponsableData trait, so the method is absent
    // rather than trait-provided — the hasMethod() guard, distinct from the trait-file check.
    $context = statusContext(new StubTypeEngine);

    expect((new DataResponseStatus)->resolveStatuses($context, NoStatusData::class))->toBe([])
        ->and($context->components->diagnostics())->toBe([]);
});
