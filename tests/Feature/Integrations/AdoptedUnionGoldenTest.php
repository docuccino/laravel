<?php

declare(strict_types=1);

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\AnswerController;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\AnswerRequest;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\CountAnswer;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\DeclaredAnswerRequest;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\DescribedAnswerRequest;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\ExtendedAnswerRequest;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\LastAnswer;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\LettersAnswer;
use Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\MeasureAnswer;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\FileAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ForwardedAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\ImageAttachment;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\LinkAttachment;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * An object the rules split by a tag, whose field a `#[BodyParameter]` declares as the sealed union the
 * same shapes are built into. The declaration names the type a client already has from the response side,
 * so the body publishes THAT — and the bounds the rules prove ride beside it, request-side, because the
 * component is shared with a response the server never bounds. Nothing else in the golden corpus declares
 * a type over a field the rules recovered, which is why no golden moved when a declaration learned to
 * refine what it is written over rather than replace it.
 *
 * The class metadata is the engine's own reflection of the fixture classes — which property each member
 * fixes, and to what, is the half a stub would only restate. Rules and return types are scripted.
 */
$engine = static function (): TypeEngine {
    $factory = new ClassMetadataFactory;
    $classes = [];
    foreach ([CountAnswer::class, MeasureAnswer::class, LettersAnswer::class, LastAnswer::class, ImageAttachment::class, LinkAttachment::class, FileAttachment::class, ForwardedAttachment::class] as $class) {
        $classes[$class] = $factory->forClass(new ClassRef($class));
    }

    $rules = TraceScript::forMethod((string) (new ReflectionClass(AnswerRequest::class))->getFileName(), AnswerRequest::class, 'rules');

    return WorkbenchEngine::make(
        classOverrides: $classes,
        analysisOverrides: [
            AnswerController::class.'::last' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(LastAnswer::class), new SourceLocation(''))]),
        ],
        traceOverrides: [
            AnswerRequest::class.'::rules' => $rules,
            DeclaredAnswerRequest::class.'::rules' => $rules,
            DescribedAnswerRequest::class.'::rules' => $rules,
            ExtendedAnswerRequest::class.'::rules' => TraceScript::forMethod((string) (new ReflectionClass(ExtendedAnswerRequest::class))->getFileName(), ExtendedAnswerRequest::class, 'rules'),
        ],
    );
};

/** @param list<string> $actions */
$routes = static fn (string ...$actions): Closure => static function (Router $router) use ($actions): void {
    foreach ($actions as $action) {
        $action === 'last'
            ? $router->get('api/zz-answers/last', [AnswerController::class, 'last'])
            : $router->post('api/zz-answers/'.$action, [AnswerController::class, $action]);
    }
};

$body = static fn (array $document, string $action): array => $document['paths']['/api/zz-answers/'.$action]['post']['requestBody']['content']['application/json']['schema'];

/** What the field publishes, less the server facts the rules recorded beside it. */
$field = static function (array $schema): array {
    unset($schema['x-docuccino']);

    return $schema;
};

// The answer a client reads: the shared union, refined by tag value, with the empty object a tagged member
// cannot describe and the null the declaration admits. Written out from the rules rather than read back:
// `measure` caps its steps at 20, `letters` sends one to twenty letters of at most eight characters each,
// and `count` is bounded no further than its member already is.
$adopted = [
    'anyOf' => [
        [
            '$ref' => '#/components/schemas/Answer',
            'anyOf' => [
                ['properties' => ['kind' => ['const' => 'count']]],
                ['properties' => ['kind' => ['const' => 'measure'], 'steps' => ['maxItems' => 20]]],
                ['properties' => ['kind' => ['const' => 'letters'], 'letters' => ['items' => ['maxLength' => 8], 'maxItems' => 20, 'minItems' => 1]]],
            ],
        ],
        ['type' => 'object', 'maxProperties' => 0],
        ['type' => 'null'],
    ],
];

it('publishes the declared union over the object the rules split, refined by what the rules prove', function () use ($engine, $routes, $body, $field, $adopted): void {
    $result = localityBuild($routes('storeDeclared', 'storeTyped', 'last'), $engine);

    assertGolden('adopted-union.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('adopted-union.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('adopted-union.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));

    $document = emittedArray($result);
    $schemas = $document['components']['schemas'];

    // Declared on the action, the body is inline; declared on the request, it is the request's component.
    // Either way the field is the shared type, and no second family of branch components is minted.
    expect($field($body($document, 'storeDeclared')['properties']['answer']))->toBe($adopted)
        ->and($field($schemas['DeclaredAnswerRequest']['properties']['answer']))->toBe($adopted)
        ->and(array_filter(array_keys($schemas), static fn (string $name): bool => str_contains($name, 'RequestAnswer')))->toBe([])
        // The bounds are the request's: the component the response sends carries none of them.
        ->and($schemas['LettersAnswer']['properties']['letters'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        // A declaration over a plain field states its type — `null` goes, the declaration outranking the
        // rules on what it says — and keeps the bound and the example its rules prove for a string.
        ->and($field($body($document, 'storeDeclared')['properties']['note']))->toBe(['description' => 'Shown beside the answer.', 'type' => 'string', 'maxLength' => 200, 'example' => 'example']);

    // `count` declares an integer where its rule is `numeric`: the declaration is what is published, so the
    // author is told, once per site the declaration is written on.
    expect(array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'attribute.body-parameter-narrower')))->toBe([
        '#[BodyParameter(name: "answer")] on Docuccino\Laravel\Tests\Fixtures\AdoptedUnion\DeclaredAnswerRequest declares less than the rules accept at `answer.value` where `kind` is count, and the declaration is what is published — so a value the server accepts there reads as invalid.',
        '#[BodyParameter(name: "answer")] declares less than the rules accept at `answer.value` where `kind` is count, and the declaration is what is published — so a value the server accepts there reads as invalid.',
    ])
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.body-parameter-union'))->toBe([]);
});

it('publishes the tag\'s enum only where something still refers to it', function () use ($engine, $routes): void {
    // The rules name `kind` by the enum's component; the declaration that wins names it by the const each
    // member fixes. What refers to the enum after that is the trail of the value the declaration
    // overrode — provenance, which no OpenAPI document carries. So the enum is published beside the trail
    // and nowhere else: a component nothing refers to is a dead type in every generated client.
    $document = localityBuild($routes('storeDeclared', 'storeTyped', 'last'), $engine)->document;

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $published = json_decode($emitter->emit($document), true, flags: JSON_THROW_ON_ERROR);

        expect(unreachableComponents($published))->toBe([])
            ->and($published['components']['schemas'])->not->toHaveKey('ShapeKind');
    }

    $full = json_decode((new UirEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);
    $winners = json_decode((new UirEmitter)->emit($document, new EmitOptions(provenance: ProvenanceLevel::Winners)), true, flags: JSON_THROW_ON_ERROR);

    expect($full['components']['schemas'])->toHaveKey('ShapeKind')
        ->and(unreachableComponents($full))->toBe([])
        ->and($winners['components']['schemas'])->not->toHaveKey('ShapeKind')
        ->and(unreachableComponents($winners))->toBe([]);

    // Where the rules' own union is published, its branches refer to the enum, and it stays.
    $rules = emittedArray(localityBuild($routes('store'), $engine));
    expect($rules['components']['schemas'])->toHaveKey('ShapeKind')
        ->and(unreachableComponents($rules))->toBe([]);
});

it('accepts the tagged bodies the server accepts, but for the one narrowing it reports', function (array|stdClass $answer, bool $accepted, bool $documented) use ($engine, $routes): void {
    // Laravel is the oracle. Every row agrees but one: a fractional `count`, which the rule accepts and the
    // declared `int` refuses — the case `attribute.body-parameter-narrower` names.
    expect(requestRuleVerdicts(DeclaredAnswerRequest::class, ['answer' => $answer], $routes('storeTyped'), $engine))->toBe([$accepted, $documented]);
})->with([
    'count' => [['kind' => 'count', 'value' => 3], true, true],
    'count, fractional' => [['kind' => 'count', 'value' => 3.5], true, false],
    'measure' => [['kind' => 'measure', 'value' => 1.5, 'steps' => [1, 2]], true, true],
    'measure, twenty-one steps' => [['kind' => 'measure', 'value' => 1.5, 'steps' => range(1, 21)], false, false],
    'letters' => [['kind' => 'letters', 'letters' => ['a', 'bb']], true, true],
    'letters, none' => [['kind' => 'letters', 'letters' => []], false, false],
    'letters, twenty-one' => [['kind' => 'letters', 'letters' => array_fill(0, 21, 'a')], false, false],
    'letters, one too long' => [['kind' => 'letters', 'letters' => ['abcdefghi']], false, false],
    'count, with letters it excludes past their bounds' => [['kind' => 'count', 'value' => 3, 'letters' => array_fill(0, 30, 'abcdefghij')], true, true],
    'a kind the enum refuses' => [['kind' => 'shape', 'value' => 3], false, false],
    'no kind on a non-empty object' => [['value' => 3], false, false],
    'the empty object' => [new stdClass, true, true],
]);

it('accepts null for the answer, as the server does', function () use ($engine, $routes): void {
    expect(requestRuleVerdicts(DeclaredAnswerRequest::class, ['answer' => null], $routes('storeTyped'), $engine))->toBe([true, true]);

    // The 3.0 document too, read as 3.0 reads it — where `nullable` beside no `type` would admit nothing.
    $downlevel = (new OpenApi30DownlevelEmitter)->emit(localityBuild($routes('storeTyped'), $engine)->document);
    expect(openApi30Admits($downlevel, '/components/schemas/DeclaredAnswerRequest', ['answer' => null]))->toBeTrue()
        ->and(openApi30Admits($downlevel, '/components/schemas/DeclaredAnswerRequest', ['answer' => new stdClass]))->toBeTrue()
        ->and(openApi30Admits($downlevel, '/components/schemas/DeclaredAnswerRequest', ['answer' => ['kind' => 'count', 'value' => 3]]))->toBeTrue()
        ->and(openApi30Admits($downlevel, '/components/schemas/DeclaredAnswerRequest', ['answer' => 'yes']))->toBeFalse();
});

it('publishes a declared union the rules do not match as written, and says why', function () use ($engine, $routes, $body, $field): void {
    $result = localityBuild($routes('storeMismatched'), $engine);
    $document = emittedArray($result);

    // Told apart by `kind` like the rules, but over other values: there is no shape to refine, so the
    // declaration stands as written — no bounds, no empty object, which a declaration outranks. Written as
    // a client sends it: the link member's title may be left out on the way in, so the union is its own
    // request shape.
    expect($field($body($document, 'storeMismatched')['properties']['answer']))->toBe(['anyOf' => [['$ref' => '#/components/schemas/AttachmentRequest'], ['type' => 'null']]])
        ->and(array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'attribute.body-parameter-union')))->toBe([
            '#[BodyParameter(name: "answer", type: "Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Attachment|null")] names a tagged union the rules\' one does not match — the rules accept one shape per `kind` (count, letters, measure), and the declared type is told apart by `kind` (file, forwarded, image, link) — so the declared type is published as written, without the bounds the rules put on each shape.',
        ])
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.body-parameter-narrower'))->toBe([]);
});

it('publishes the rules\' own union where nothing is declared', function () use ($engine, $routes): void {
    $schemas = emittedArray(localityBuild($routes('store'), $engine))['components']['schemas'];

    expect($schemas['AnswerRequest']['properties']['answer']['anyOf'][0]['discriminator']['mapping'])->toBe([
        'count' => '#/components/schemas/AnswerRequestAnswerCount',
        'letters' => '#/components/schemas/AnswerRequestAnswerLetters',
        'measure' => '#/components/schemas/AnswerRequestAnswerMeasure',
    ]);
});

it('leaves the shared union as a build without the request routes publishes it', function () use ($engine, $routes): void {
    // The refinement is a request fact. Adding the routes that declare the union must not move one byte of
    // the components the response side already published.
    $alone = emittedArray(localityBuild($routes('last'), $engine))['components']['schemas'];
    $with = emittedArray(localityBuild($routes('storeDeclared', 'storeTyped', 'last'), $engine))['components']['schemas'];

    foreach (['Answer', 'CountAnswer', 'LettersAnswer', 'MeasureAnswer', 'LastAnswer'] as $name) {
        expect($with[$name])->toBe($alone[$name]);
    }
});

it('adopts the union the same whichever route meets it first', function () use ($engine, $routes): void {
    // The declared type's components are read once its conversion has registered them, so a route that
    // declares the union before any response sends it adopts it all the same.
    $first = (new UirEmitter)->emit(localityBuild($routes('storeDeclared', 'storeTyped'), $engine)->document);
    $reversed = (new UirEmitter)->emit(localityBuild($routes('storeTyped', 'storeDeclared'), $engine)->document);

    expect($reversed)->toBe($first)
        ->and($first)->toContain('"maxLength": 8');
});

it('builds the adopted union warm as cold, notes included', function () use ($engine, $routes): void {
    $warm = assertWarmEqualsCold($routes('storeDeclared', 'storeTyped', 'last'), $routes('storeDeclared', 'storeTyped', 'last'), $engine);

    expect(diagnosticsCoded($warm->diagnostics, 'attribute.body-parameter-narrower'))->toHaveCount(2);
});

it('keeps the rules\' own union where a declaration names no union to adopt it by', function () use ($engine, $routes): void {
    // A description alone states no type, so it has nothing to adopt the object by: the object keeps the
    // union a build without the declaration publishes, its members named for the request as ever — the
    // names a client generator turns into types do not move because a description was written.
    $schemas = emittedArray(localityBuild($routes('storeDescribed'), $engine))['components']['schemas'];
    $answer = $schemas['DescribedAnswerRequest']['properties']['answer'];

    expect($answer['description'] ?? null)->toBe('The answer, in the shape its kind takes.')
        ->and($answer['anyOf'][0]['discriminator']['mapping'] ?? null)->toBe([
            'count' => '#/components/schemas/DescribedAnswerRequestAnswerCount',
            'letters' => '#/components/schemas/DescribedAnswerRequestAnswerLetters',
            'measure' => '#/components/schemas/DescribedAnswerRequestAnswerMeasure',
        ]);
});

it('publishes what the declaration and the rules both accept, and names what the declared type does not list', function () use ($engine, $routes, $body, $field): void {
    $result = localityBuild($routes('storeExtended'), $engine);
    $properties = $body(emittedArray($result), 'storeExtended')['properties'];

    // `answer` is `required` and not nullable, so the server answers a null — and an empty object — with a
    // 422: neither is offered, though the declaration admits null. `count` also requires a `reason` no
    // answer class has; the refinement publishes it, and the author is told a typed client cannot send it.
    expect($field($properties['answer']))->toBe([
        '$ref' => '#/components/schemas/Answer',
        'anyOf' => [
            ['properties' => ['kind' => ['const' => 'count'], 'reason' => ['type' => 'string']], 'required' => ['reason']],
            ['properties' => ['kind' => ['const' => 'measure'], 'steps' => ['maxItems' => 20]]],
            ['properties' => ['kind' => ['const' => 'letters'], 'letters' => ['items' => ['maxLength' => 8], 'maxItems' => 20, 'minItems' => 1]]],
        ],
    ])
        // A declaration over a plain field keeps the bounds still true of its type, and drops the value
        // list it cannot hold — with the names and the example that described those values.
        ->and($field($properties['level']))->toBe(['type' => 'integer'])
        ->and($field($properties['ratio']))->toEqual(['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'example' => 1])
        ->and(array_map(static fn ($d): string => $d->message, diagnosticsCoded($result->diagnostics, 'attribute.body-parameter-unlisted')))->toBe([
            '#[BodyParameter(name: "answer")] declares a type that does not list `answer.reason` where `kind` is count, which the rules require — the document publishes it beside the type, but a client built from the type alone has no field to send it in.',
        ]);
});
