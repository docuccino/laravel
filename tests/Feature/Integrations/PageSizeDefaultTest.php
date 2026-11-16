<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\PaginatedResourceParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\Support\PageSizeDefaultExtension;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeKey;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * A page size's literal fallback is what the server serves when the key is absent, and OAS says a schema's
 * `default` MUST conform to that schema: a client generator fills it in, so one past the bounds the request
 * validates is a request the client builds and the server refuses. The size producers write the default a
 * phase before the validation rules bound the key, so the fallback is held to the bounds at `Finalize`, and
 * where it falls outside them it is stated in prose instead — never clamped, since 50 is what is served.
 *
 * @param  array<string, mixed>  $bounds  keywords a later layer writes onto the size parameter
 * @return array{schema: array<string, mixed>, description: ?string}
 */
function runPageSizeDefault(OperationExtension $producer, string $chain, string $receiver, array $bounds): array
{
    $collection = new ClassT(
        'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
        [new ClassT('Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource')],
    );

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/articles'),
        actionRef: new ActionRef('', 'App\\Articles', 'index'),
        attributes: new AttributeSet([]),
        engine: new StubTypeEngine(
            analyses: ['App\\Articles::index' => new ActionAnalysis(returns: [new ReturnSite($collection, new SourceLocation(''))])],
            traces: ['App\\Articles::index' => TraceScript::forChain(
                $chain,
                $receiver,
                variableTypes: ['request' => new ClassT('Illuminate\\Http\\Request')],
            )],
        ),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    $operation = new OperationDraft;
    $producer->handle($operation, $context);

    // What the request phase's validation recovery writes onto the same key, a phase after the producer.
    foreach ($bounds as $keyword => $value) {
        $operation->parameter('query', 'per_page')->schema()->set($keyword, $value, Contribution::integration('form-request'));
    }

    (new PageSizeDefaultExtension)->handle($operation, $context);

    $parameter = [];
    foreach ($operation->freeze()->parameters as $frozen) {
        if ($frozen->name === 'per_page') {
            $parameter = $frozen->toArray();
        }
    }
    $schema = $parameter['schema'] ?? [];
    unset($schema['x-docuccino']);

    return ['schema' => $schema, 'description' => $parameter['description'] ?? null];
}

/** A producer per size writer, both of which mint the size through {@see PaginatorPageParameter::size()}. */
dataset('page-size producers', [
    'query builder' => [
        static fn (): OperationExtension => new QueryBuilderParametersExtension,
        "QueryBuilder::for('App\\\\Models\\\\Article')->paginate(\$request->integer('per_page', 50))",
        'Spatie\\QueryBuilder\\QueryBuilder',
    ],
    'resource collection' => [
        static fn (): OperationExtension => new PaginatedResourceParametersExtension,
        "\$q->paginate(\$request->integer('per_page', 50))",
        'Illuminate\\Database\\Eloquent\\Builder',
    ],
]);

it('states a fallback past the validated maximum in prose rather than as a default the schema rejects', function (Closure $producer, string $chain, string $receiver): void {
    $published = runPageSizeDefault($producer(), $chain, $receiver, ['minimum' => 1, 'maximum' => 20]);

    // The bounds are what a sent value must meet; the fallback is what an absent one gets, unclamped.
    expect($published['schema'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 20])
        ->and($published['description'])->toBe("Number of items per page.\n\nDefaults to `50`.");
})->with('page-size producers');

it('keeps a fallback the validated bounds admit as the schema default', function (Closure $producer, string $chain, string $receiver): void {
    $published = runPageSizeDefault($producer(), $chain, $receiver, ['minimum' => 1, 'maximum' => 100]);

    expect($published['schema'])->toBe(['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100])
        ->and($published['description'])->toBe('Number of items per page.');
})->with('page-size producers');

it('holds the fallback to every bound and value list a schema can carry', function (array $bounds, bool $admitted): void {
    $published = runPageSizeDefault(
        new PaginatedResourceParametersExtension,
        "\$q->paginate(\$request->integer('per_page', 50))",
        'Illuminate\\Database\\Eloquent\\Builder',
        $bounds,
    );

    expect(array_key_exists('default', $published['schema']))->toBe($admitted)
        ->and(str_contains((string) $published['description'], 'Defaults to `50`.'))->toBe(! $admitted);
})->with([
    'below the minimum' => [['minimum' => 60], false],
    'at the minimum' => [['minimum' => 50], true],
    'above the maximum' => [['maximum' => 20], false],
    'at the maximum' => [['maximum' => 50], true],
    'at the exclusive minimum' => [['exclusiveMinimum' => 50], false],
    'past the exclusive minimum' => [['exclusiveMinimum' => 49], true],
    'at the exclusive maximum' => [['exclusiveMaximum' => 50], false],
    'within the exclusive maximum' => [['exclusiveMaximum' => 51], true],
    'missing from the value list' => [['enum' => [10, 20]], false],
    'in the value list' => [['enum' => [10, 50]], true],
    // A schema value is a JSON value: the string "50" is not the integer the server falls back to.
    'in the value list only as a string' => [['enum' => ['10', '50']], false],
    'no bound at all' => [[], true],
]);

it("leaves a default a higher layer wrote alone, since it is that author's statement", function (): void {
    // An author who pins the very fallback the code reads still owns it, bounds or not.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/articles'),
        actionRef: new ActionRef('', 'App\\Articles', 'index'),
        attributes: new AttributeSet([]),
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
    PageSizeDefaultExtension::record($context, new RequestPageSizeKey('per_page', 50));

    $operation = new OperationDraft;
    $schema = $operation->parameter('query', 'per_page')->schema();
    $schema->set('type', 'integer', Contribution::integration('api-resources'));
    $schema->set('default', 50, Contribution::attribute());
    $schema->set('maximum', 20, Contribution::integration('form-request'));

    (new PageSizeDefaultExtension)->handle($operation, $context);

    expect($schema->resolvedField('default'))->toBe(50)
        ->and($operation->freeze()->parameters[0]->toArray())->not->toHaveKey('description');
});

it('records nothing for a size read without a literal fallback', function (): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/articles'),
        actionRef: new ActionRef('', 'App\\Articles', 'index'),
        attributes: new AttributeSet([]),
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
    PageSizeDefaultExtension::record($context, new RequestPageSizeKey('per_page'));

    expect($context->notes()->all())->toBe([]);
});

it('runs in every document, after the request phase has bounded the key', function (): void {
    expect(DefaultExtensions::all(new DocumentConfig('default', [])))->toContain(PageSizeDefaultExtension::class)
        ->and((new PageSizeDefaultExtension)->phase()->value)->toBeGreaterThan(OperationPhase::Request->value);
});
