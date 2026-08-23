<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Workbench\App\Models\Gadget;

/**
 * End-to-end proof of the sort/include enum METADATA in-process: a scripted trace over the workbench
 * `Gadget` drives the real extension, so per-value prose lands from an allow-list comment, the
 * relation method's docblock and the model's `@property` summary, member names are minted onto both
 * hint spellings, and a name collision degrades loudly — without the real engine.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: list<Diagnostic>, 2: list<string>}
 */
function runListMetadata(string $chain, ?QueryBuilderConfig $config = null): array
{
    $gadgetFile = (string) (new ReflectionClass(Gadget::class))->getFileName();

    $engine = new StubTypeEngine(
        traces: ['App\\Gadgets::index' => TraceScript::forChain($chain)],
        classes: [
            Gadget::class => new ClassMetadata(Gadget::class, [
                new PropertyMetadata('score', new UnknownT('test'), 'The gadget\'s popularity score.'),
            ], dependencyFiles: [$gadgetFile]),
        ],
    );

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension($config ?? new QueryBuilderConfig))->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context->components->diagnostics(), $context->dependencyFiles()];
}

it('describes and names every include and sort value end to end', function (): void {
    $chain = <<<'PHP'
    QueryBuilder::for(\Workbench\App\Models\Gadget::class)->allowedIncludes([
        // The beacon currently paired with this gadget.
        'beacon',
    ])->allowedSorts(['score'])->defaultSort('-score')->paginate()
    PHP;

    [$byName, , $dependencyFiles] = runListMetadata($chain);

    $include = $byName['include']['schema']['items'];
    expect($include['enum'])->toBe(['beacon', 'beaconCount', 'beaconExists'])
        ->and($include['x-enumDescriptions'])->toBe([
            'beacon' => 'The beacon currently paired with this gadget.',
            'beaconCount' => 'Count of related `beacon` records.',
            'beaconExists' => 'Whether related `beacon` records exist.',
        ])
        ->and($include['x-enum-descriptions'])->toBe([
            'The beacon currently paired with this gadget.',
            'Count of related `beacon` records.',
            'Whether related `beacon` records exist.',
        ])
        ->and($include['x-enum-varnames'])->toBe(['Beacon', 'BeaconCount', 'BeaconExists'])
        ->and($include['x-enumNames'])->toBe(['Beacon', 'BeaconCount', 'BeaconExists']);

    $sort = $byName['sort']['schema'];
    expect($sort['default'])->toBe(['-score'])
        ->and($sort['items']['x-enumDescriptions'])->toBe([
            'score' => 'The gadget\'s popularity score.',
            '-score' => 'The gadget\'s popularity score. (descending)',
        ])
        ->and($sort['items']['x-enum-varnames'])->toBe(['Score', 'ScoreDesc']);

    // The prose was read off the model — its file (where the relation docblock and @property live)
    // must key the fragment cache.
    expect($dependencyFiles)->toContain((string) (new ReflectionClass(Gadget::class))->getFileName());
});

it('falls back to the relation docblock when the include entry carries no comment', function (): void {
    [$byName] = runListMetadata("QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedIncludes(['beacon'])->paginate()");

    expect($byName['include']['schema']['items']['x-enumDescriptions']['beacon'])
        ->toBe('A belongsTo whose default foreign key (`beacon_id`) types off the related model\'s uuid key.');
});

it('describes and names sparse-fieldset values end to end, bare group under the unbracketed name', function (): void {
    $chain = <<<'PHP'
    QueryBuilder::for(\Workbench\App\Models\Gadget::class)->allowedFields([
        // The gadget's shelf label.
        'label',
        'score',
        'beacons.frequency',
    ])->paginate()
    PHP;

    [$byName] = runListMetadata($chain);

    $bare = $byName['fields']['schema']['items'];
    expect($bare['enum'])->toBe(['label', 'score'])
        ->and($bare['x-enumDescriptions'])->toBe([
            'label' => 'The gadget\'s shelf label.',
            'score' => 'The gadget\'s popularity score.',
        ])
        ->and($bare['x-enum-varnames'])->toBe(['Label', 'Score']);

    // A prefixed group names a related table this pass can't map to a model — undescribed, still named.
    $related = $byName['fields[beacons]']['schema']['items'];
    expect($related['enum'])->toBe(['frequency'])
        ->and($related)->not->toHaveKey('x-enumDescriptions')
        ->and($related['x-enum-varnames'])->toBe(['Frequency']);
});

it('reads a sort entry comment off the real allow-list source, both directions', function (): void {
    $chain = <<<'PHP'
    QueryBuilder::for(\Workbench\App\Models\Gadget::class)->allowedSorts([
        // Alphabetical by shelf label.
        'label',
        'score',
    ])->paginate()
    PHP;

    [$byName] = runListMetadata($chain);

    // The comment beats the model's @property prose for `label`; `score` has no comment and falls
    // back to it, so both directions of both bases are described.
    expect($byName['sort']['schema']['items']['x-enumDescriptions'])->toBe([
        'label' => 'Alphabetical by shelf label.',
        '-label' => 'Alphabetical by shelf label. (descending)',
        'score' => 'The gadget\'s popularity score.',
        '-score' => 'The gadget\'s popularity score. (descending)',
    ]);
});

it('reports a member-name collision inside one fields group', function (): void {
    [$byName, $diagnostics] = runListMetadata(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFields(['articles.beta_gamma', 'articles.betaGamma'])->paginate()",
    );

    expect($byName['fields[articles]']['schema']['items']['enum'])->toBe(['beta_gamma', 'betaGamma']);

    $collisions = array_values(array_filter($diagnostics, fn ($d): bool => $d->code === 'query-builder.enum-name-collision'));
    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]->message)->toContain('"fields[articles]"');
});

it('publishes distinct value-derived names and one diagnostic when two values contest a member name', function (): void {
    [$byName, $diagnostics] = runListMetadata(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedIncludes(['alpha.beta', 'alphaBeta'])->paginate()",
    );

    $items = $byName['include']['schema']['items'];
    expect($items['enum'])->toBe(['alpha', 'alphaCount', 'alphaExists', 'alpha.beta', 'alphaBeta', 'alphaBetaCount', 'alphaBetaExists'])
        ->and($items['x-enum-varnames'])->toBe(['Alpha', 'AlphaCount', 'AlphaExists', 'AlphaDotBeta', 'AlphaBeta', 'AlphaBetaCount', 'AlphaBetaExists']);

    $collisions = array_values(array_filter($diagnostics, fn ($d): bool => $d->code === 'query-builder.enum-name-collision'));
    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]->message)->toBe('Values "alpha.beta", "alphaBeta" of the "include" parameter would share one SDK enum member name, so distinct value-derived names were published instead.');
});

/**
 * The report has to read the same predicate the emission does: wherever the lists degrade to plain
 * strings no member name was published, so telling the author that "distinct value-derived names were
 * published instead" would name output nobody can find.
 */
it('says nothing about member names where the lists degraded to plain strings', function (QueryBuilderConfig $config): void {
    [$byName, $diagnostics] = runListMetadata(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedIncludes(['alpha.beta', 'alphaBeta'])->paginate()",
        $config,
    );

    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics);

    expect($byName['include']['schema']['type'])->toBe('string')
        ->and($byName['include']['schema'])->not->toHaveKey('items')
        ->and($codes)->not->toContain('query-builder.enum-name-collision');
})->with([
    'a custom delimiter' => [new QueryBuilderConfig(delimiter: '|')],
    'a pre-v7 package' => [new QueryBuilderConfig(spatieMajor: 6)],
]);
