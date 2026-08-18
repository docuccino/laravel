<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerResponseBuilder;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TracedProblemData;

/**
 * What a consumer is told about a member the response only sometimes has. `X ?? new Optional` is spatie's
 * "omit this key" idiom: whether the key is rendered depends on a runtime value, so the body that reaches
 * the consumer has it on some responses and not on others.
 *
 * Everything the engine contributes comes from the fixture app through the real engine — the recovered
 * member map is the whole point — and only the class the mapper reflects is a loadable in-process twin of
 * the one analysed.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * The documented `application/problem+json` body for one fixture-app method, plus the component its schema
 * references: the real engine's recovered response, its payload re-keyed onto the twin the mapper can
 * reflect.
 *
 * @return array{body: array<string, mixed>, component: array<string, mixed>}
 */
function conditionalMemberBody(string $method): array
{
    $analysis = ActionAnalysis::fromArray(['returns' => FixtureRunner::analyzeCallable(
        'app/Exceptions/RefinerEdgeCases.php',
        'App\\Exceptions\\RefinerEdgeCases',
        $method,
    )['returns']]);

    $recovered = $analysis->returns[0]->type;
    expect($recovered)->toBeInstanceOf(ClassT::class);

    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\ProblemDocumentData'));
    $engine = new StubTypeEngine(classes: [
        TracedProblemData::class => new ClassMetadata(TracedProblemData::class, $real->properties, $real->summary),
    ]);

    $payload = $recovered->typeArgs[0] ?? null;
    expect($payload)->toBeInstanceOf(ClassT::class)
        ->and($payload->fqcn)->toBe('App\\Data\\ProblemDocumentData');

    $onTwin = new ClassT($recovered->fqcn, [
        new ClassT(TracedProblemData::class),
        ...array_slice($recovered->typeArgs, 1),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: [new DataSchema, ...DefaultTypeMappers::all()],
        ),
    );

    $draft = HandlerResponseBuilder::build(
        new ActionAnalysis(returns: [new ReturnSite($onTwin, new SourceLocation(''))]),
        $context,
        Contribution::integration('inferred-handler'),
    );

    $frozen = $draft?->freeze()->toArray() ?? [];
    $body = $frozen['content']['application/problem+json'] ?? [];
    $component = $context->components->schemas()['TracedProblemData'] ?? [];

    return [
        'body' => is_array($body) ? $body : [],
        'component' => is_array($component) ? $component : [],
    ];
}

it('does not promise a member whose value the caller may not have', function (): void {
    // The factory writes `errors: $errors ?? new Optional` and this caller passes a value that may be null,
    // so some of these responses have no `errors` key at all. Showing it in the example would tell a reader
    // to expect a key that need not be there — and the schema beside it (correctly) never said it would be.
    // The member is still DESCRIBED: an omitted illustration is not an omitted member.
    ['body' => $body, 'component' => $component] = conditionalMemberBody('nullableOptionalMember');

    expect($body['schema']['$ref'] ?? null)->toBe('#/components/schemas/TracedProblemData')
        ->and($body['example'] ?? [])->not->toHaveKey('errors')
        ->and($component['properties'] ?? [])->toHaveKey('errors')
        ->and($component['required'] ?? [])->not->toContain('errors');
})->group('fixture');

it('still promises the members every response on the branch carries', function (): void {
    // The same body's other five: four written as literals at the call site, and `instance` supplied
    // unconditionally by the factory itself. Nothing about the conditional member weakens those.
    $body = conditionalMemberBody('nullableOptionalMember')['body'];

    expect($body['example'] ?? [])->toBe([
        'type' => 'https://errors.test/problems/unprocessable',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'detail' => 'The caller may or may not have field errors to report.',
        'instance' => 'string',
    ]);
})->group('fixture');
