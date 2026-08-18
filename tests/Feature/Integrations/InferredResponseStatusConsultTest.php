<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\InferredResponsesExtension;

/**
 * The consult path — the gate that decides WHEN calculateResponseStatus() overrides re-home the
 * inferred 200 (the real-world miss: overrides were consulted only for a single bare Data ClassT, so a Data
 * returned as one arm of a UNION never had its status folded). Drives the real
 * InferredResponsesExtension with a stub engine scripting the action's return type(s) and a stub
 * ResponseStatusResolver standing in for the folded overrides.
 */

/** @param list<DType> $returnTypes */
function inferredStatuses(array $returnTypes, array $overrides): array
{
    $ref = new ActionRef('', 'App\\Http\\C', 'index');
    $engine = new StubTypeEngine(analyses: [
        $ref->symbol() => new ActionAnalysis(
            returns: array_map(static fn (DType $t): ReturnSite => new ReturnSite($t, new SourceLocation('')), $returnTypes),
        ),
    ]);

    $resolver = new class($overrides) implements ResponseStatusResolver
    {
        /** @param array<string, list<int>> $map */
        public function __construct(private array $map) {}

        public function resolveStatuses(RouteContext $context, string $fqcn): array
        {
            return $this->map[$fqcn] ?? [];
        }
    };

    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: $ref,
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            responseStatusResolvers: [$resolver],
        ),
    );

    $operation = new OperationDraft;
    (new InferredResponsesExtension)->handle($operation, $context);

    // PHP coerces numeric-string response keys to int — restore the strings and sort for determinism.
    $statuses = array_map(strval(...), array_keys($operation->freeze()->responses));
    sort($statuses);

    return $statuses;
}

it('re-homes a single bare Data return to its overridden status', function (): void {
    $statuses = inferredStatuses(
        [new ClassT('App\\Data\\CreatedThing')],
        ['App\\Data\\CreatedThing' => [201]],
    );

    expect($statuses)->toBe(['201']);
});

it('documents every status of a multi-status (ternary) override on a single Data return', function (): void {
    $statuses = inferredStatuses(
        [new ClassT('App\\Data\\MaybeCreated')],
        ['App\\Data\\MaybeCreated' => [200, 201]],
    );

    expect($statuses)->toBe(['200', '201']);
});

it('re-homes each member of a union-of-Data return to its own status (the multi-challenge-DTO shape)', function (): void {
    // One action returning `AuthSuccessData|MfaChallengeData` as a union: the success arm has no
    // override (stays 200), the challenge arm folds to 422. Both must be documented.
    $statuses = inferredStatuses(
        [UnionT::of([new ClassT('App\\Data\\AuthSuccess'), new ClassT('App\\Data\\MfaChallenge')])],
        ['App\\Data\\MfaChallenge' => [422]],
    );

    expect($statuses)->toBe(['200', '422']);
});

it('leaves a bare Data return at 200 when no override folds', function (): void {
    $statuses = inferredStatuses(
        [new ClassT('App\\Data\\Plain')],
        [],
    );

    expect($statuses)->toBe(['200']);
});
