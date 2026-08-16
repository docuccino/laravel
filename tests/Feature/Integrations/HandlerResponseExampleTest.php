<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerResponseBuilder;

/**
 * The example an inferred handler response carries has to be a valid instance of the schema beside it. Only
 * literal members fold in, so a body requiring members that stayed widened — an RFC 9457 problem document
 * folds `type` and `status` but not `title`/`detail`/`instance` — has the rest filled from the schema:
 * type-derived placeholders, plus the real response status. The fill is confined to examples, which are
 * illustrative by definition; nothing invented ever reaches a schema.
 *
 * When the body is an object the engine watched being constructed, the arguments it was built with decide
 * membership instead: supplied beats optional (this branch passed it, so this response has it) and
 * unsupplied beats required (this branch didn't, so it doesn't). An argument that renders the key only on
 * some responses decides nothing, and the schema answers for it.
 */
function handlerContext(?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', []),
        typeMappers: DefaultTypeMappers::all(),
    );
}

/**
 * A handler analysis returning `JsonResponse<$payload, $status, 'application/problem+json'[, $members]>`.
 * A DType status stands in for one that didn't fold, which is what a `$this->status` read makes.
 */
function handlerAnalysis(DType $payload, int|DType $status, ?ArrayShapeT $members = null): ActionAnalysis
{
    $args = [$payload, is_int($status) ? new LiteralT($status) : $status, new LiteralT('application/problem+json')];
    if ($members !== null) {
        $args[] = $members;
    }

    return new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', $args),
        new SourceLocation(''),
    )]);
}

/**
 * A context whose engine expands one problem-document class: five required members plus two the class
 * declares nullable, so the schema calls them optional and only the constructor arguments can say whether a
 * given response carries them.
 */
function problemDocumentContext(): RouteContext
{
    $optional = static fn (DType $type): DType => UnionT::of([$type, new NullT]);

    return handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\ProblemDocument' => new ClassMetadata('App\\Data\\ProblemDocument', [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('detail', ScalarT::string()),
            new PropertyMetadata('instance', $optional(ScalarT::string())),
            new PropertyMetadata('errors', $optional(new ListT(new ArrayShapeT([
                new ArrayShapeField('pointer', ScalarT::string()),
            ])))),
        ]),
    ]));
}

/**
 * The member map the engine channels a watched construction through: name → folded literal or "supplied".
 * A name in `$conditional` is one the branch renders only sometimes — spatie's `X ?? new Optional`.
 */
function suppliedMembers(array $members, array $conditional = []): ArrayShapeT
{
    $fields = [];
    foreach ($members as $name => $value) {
        $fields[] = new ArrayShapeField(
            $name,
            $value === null ? new UnknownT('constructor argument not folded') : new LiteralT($value),
            in_array($name, $conditional, true),
        );
    }

    return new ArrayShapeT($fields);
}

it('completes an example whose required members did not all fold', function (): void {
    // type + status fold; title and detail stay widened strings but are still required.
    $payload = new ArrayShapeT([
        new ArrayShapeField('type', new LiteralT('https://httpstatuses.io/422')),
        new ArrayShapeField('title', ScalarT::string()),
        new ArrayShapeField('status', new StatusMarkerT),
        new ArrayShapeField('detail', ScalarT::string()),
    ], false);

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis($payload, 422),
        handlerContext(),
        Contribution::integration('inferred-handler'),
    );

    $frozen = $draft?->freeze()->toArray() ?? [];
    $content = $frozen['content']['application/problem+json'] ?? [];

    // type folded, status came off the response, and the two widened strings get placeholders — so the
    // example is a valid instance of the schema instead of a partial one that fails against it.
    expect($content['example'])->toBe([
        'type' => 'https://httpstatuses.io/422',
        'title' => 'string',
        'status' => 422,
        'detail' => 'string',
    ])
        ->and($content['schema']['required'] ?? [])->toBe(['type', 'title', 'status', 'detail'])
        ->and($content['schema']['properties']['status']['const'] ?? null)->toBe(422);
});

it('keeps an example whose literals cover every required member', function (): void {
    $payload = new ArrayShapeT([
        new ArrayShapeField('message', new LiteralT('Not Found')),
        new ArrayShapeField('status', new StatusMarkerT),
    ], false);

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis($payload, 404),
        handlerContext(),
        Contribution::integration('inferred-handler'),
    );

    $frozen = $draft?->freeze()->toArray() ?? [];

    expect($frozen['content']['application/problem+json']['example'] ?? null)
        ->toBe(['message' => 'Not Found', 'status' => 404]);
});

it('keeps an example when the shape requires nothing at all', function (): void {
    // Every member optional → no `required` keyword → nothing for the example to fail to cover.
    $payload = new ArrayShapeT([
        new ArrayShapeField('hint', new LiteralT('retry'), optional: true),
    ], false);

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis($payload, 503),
        handlerContext(),
        Contribution::integration('inferred-handler'),
    );

    $frozen = $draft?->freeze()->toArray() ?? [];

    expect($frozen['content']['application/problem+json']['example'] ?? null)->toBe(['hint' => 'retry']);
});

/** The example of a `ClassT` body whose construction the engine watched, through the hoisted `$ref`. */
function objectExample(array $members, int $status = 422): array
{
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\ProblemDocument'), $status, suppliedMembers($members)),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
    );

    $frozen = $draft?->freeze()->toArray() ?? [];
    $example = $frozen['content']['application/problem+json']['example'] ?? null;

    return is_array($example) ? $example : [];
}

it('shows the values an object body was constructed with, through its hoisted $ref', function (): void {
    // The schema arrives as a bare `$ref` to the component, so the members are only visible by following
    // it. Three of the six arguments folded at the call site; the rest are supplied but unknowable, and a
    // supplied member is in the body whatever the schema says about its optionality.
    expect(objectExample([
        'type' => 'https://errors.test/problems/unprocessable',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'detail' => null,
        'instance' => null,
        'errors' => null,
    ]))->toBe([
        'type' => 'https://errors.test/problems/unprocessable',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'detail' => 'string',
        // Optional on the component, but this branch passed it — so this response carries it.
        'instance' => 'string',
        // An `items` schema renders as a list OF something; an empty array would show nothing at all.
        'errors' => [['pointer' => 'string']],
    ]);
});

it('leaves out a member the branch renders only sometimes', function (): void {
    // `instance` was passed, but as a value that renders the key on some responses and omits it on others.
    // Showing it would tell a reader of the example to expect a key that need not be there; the component
    // still describes it, which is where a sometimes-present member belongs.
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(
            new ClassT('App\\Data\\ProblemDocument'),
            422,
            suppliedMembers(
                ['type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422, 'detail' => null, 'instance' => null],
                conditional: ['instance'],
            ),
        ),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)->toBe([
        'type' => 'about:blank',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'detail' => 'string',
    ]);
});

it('still shows a sometimes-rendered member the schema requires of every response', function (): void {
    // The schema is the stronger claim in this direction: it says every response carries `title`, so an
    // example without it would fail against the very schema beside it. The fill applies as it would to any
    // member no argument accounted for.
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(
            new ClassT('App\\Data\\ProblemDocument'),
            500,
            suppliedMembers(
                ['type' => 'about:blank', 'title' => null, 'status' => 500, 'detail' => 'Something went wrong.'],
                conditional: ['title'],
            ),
        ),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)->toBe([
        'type' => 'about:blank',
        'title' => 'string',
        'status' => 500,
        'detail' => 'Something went wrong.',
    ]);
});

it('leaves out an optional member the branch did not construct', function (): void {
    // The same component, a branch that passed four of the six arguments: the example is that branch's
    // body, not the union of every branch's.
    expect(objectExample([
        'type' => 'about:blank',
        'title' => 'Internal Server Error',
        'status' => 500,
        'detail' => 'Something went wrong.',
    ], 500))->toBe([
        'type' => 'about:blank',
        'title' => 'Internal Server Error',
        'status' => 500,
        'detail' => 'Something went wrong.',
    ]);
});

it('still fills a required member no argument accounted for', function (): void {
    // `title` and `detail` are required by the schema and absent from the map — the example would fail
    // validation against its own schema without them, so the type-derived fill still applies.
    expect(objectExample(['type' => 'about:blank', 'status' => 404], 404))->toBe([
        'type' => 'about:blank',
        'title' => 'string',
        'status' => 404,
        'detail' => 'string',
    ]);
});

it('documents an object body under the status its own construction folded', function (): void {
    // The shape a Data object that writes its own `toResponse()` produces: `status: $this->status` is a
    // property read, so the RESPONSE status never folds — while the construction that built the object
    // folded `status: 503` fine. Documenting the hint here would file a 503 body under 500.
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(
            new ClassT('App\\Data\\ProblemDocument'),
            new UnknownT('status not folded'),
            suppliedMembers(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => null]),
        ),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
        statusHint: 500,
    );

    $frozen = $draft?->freeze()->toArray() ?? [];

    expect($draft?->status)->toBe('503')
        ->and($frozen['description'] ?? null)->toBe('Service Unavailable')
        ->and($frozen['content']['application/problem+json']['example']['status'] ?? null)->toBe(503);
});

it('keeps the hint when nothing in the body states a status either', function (): void {
    // Neither side folded, so there is nothing to prefer: the exception's own classification stands.
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(
            new ClassT('App\\Data\\ProblemDocument'),
            new UnknownT('status not folded'),
            suppliedMembers(['type' => null, 'title' => null, 'status' => null, 'detail' => null]),
        ),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
        statusHint: 500,
    );

    expect($draft?->status)->toBe('500');
});

it('fills a member from the value its own schema states, not from its type', function (): void {
    // A spatie property's `@example`, and the PHP default a property carries, both reach the component
    // schema. Either is the app's own word for what the member looks like, so `"string"` is strictly worse
    // — and `const` still outranks both, because that one is a claim rather than an illustration.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\StatedProblem' => new ClassMetadata('App\\Data\\StatedProblem', [
            new PropertyMetadata('type', ScalarT::string()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('detail', ScalarT::string()),
        ]),
    ]));

    // The schema the converter would hand back, with the annotations the spatie integration adds.
    $context->components->registerSchema('StatedProblem', [
        'type' => 'object',
        'properties' => [
            'type' => ['type' => 'string', 'default' => 'about:blank'],
            'title' => ['type' => 'string', 'example' => 'Unprocessable Content'],
            'detail' => ['type' => 'string', 'const' => 'pinned', 'default' => 'ignored'],
        ],
        'required' => ['type', 'title', 'detail'],
    ], 'App\\Data\\StatedProblem');

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\StatedProblem'), 422),
        $context,
        Contribution::integration('inferred-handler'),
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)->toBe([
        'type' => 'about:blank',
        'title' => 'Unprocessable Content',
        'detail' => 'pinned',
    ]);
});

it('still pins a status member to the response status over a stated default', function (): void {
    // `status` echoes the status THIS response is documented under (RFC 9457's convention). A default the
    // class happens to carry describes some other response, so it must not win here.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\DefaultedStatus' => new ClassMetadata('App\\Data\\DefaultedStatus', [
            new PropertyMetadata('status', ScalarT::int()),
        ]),
    ]));

    $context->components->registerSchema('DefaultedStatus', [
        'type' => 'object',
        'properties' => ['status' => ['type' => 'integer', 'default' => 500]],
        'required' => ['status'],
    ], 'App\\Data\\DefaultedStatus');

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\DefaultedStatus'), 404),
        $context,
        Contribution::integration('inferred-handler'),
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)
        ->toBe(['status' => 404]);
});

it('leaves out a supplied member the schema declares no type for', function (): void {
    // An unresolved property type reaches the document as a description and nothing else. The member IS in
    // this response, but `"string"` for something that may well be a list would state what the code never
    // said. A bare `array` property with no docblock to refine it lands here.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\OpaqueProblem' => new ClassMetadata('App\\Data\\OpaqueProblem', [
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('errors', UnionT::of([new UnknownT('untyped array'), new NullT])),
        ]),
    ]));

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\OpaqueProblem'), 422, suppliedMembers(['title' => null, 'errors' => null])),
        $context,
        Contribution::integration('inferred-handler'),
    );

    $content = $draft?->freeze()->toArray()['content']['application/problem+json'] ?? [];

    expect($content['example'] ?? null)->toBe(['title' => 'string'])
        // The schema still carries the member; only the illustration declines to invent one.
        ->and($content['schema']['$ref'] ?? null)->toBe('#/components/schemas/OpaqueProblem');
});

it('illustrates a nullable member through its non-null branch', function (): void {
    // A nullable object can't be a `type: [x, null]` array, so it comes out as
    // `anyOf: [{$ref: …}, {type: null}]`. Illustrating the null branch would show nothing at all, and the
    // reference has to be followed inside the branch to reach the nested object's own members.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\NullableProblem' => new ClassMetadata('App\\Data\\NullableProblem', [
            new PropertyMetadata('hint', UnionT::of([new ClassT('App\\Data\\Hint'), new NullT])),
            new PropertyMetadata('codes', new ListT(ScalarT::string())),
        ]),
        'App\\Data\\Hint' => new ClassMetadata('App\\Data\\Hint', [
            new PropertyMetadata('text', ScalarT::string()),
        ]),
    ]));

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\NullableProblem'), 422, suppliedMembers(['hint' => null, 'codes' => null])),
        $context,
        Contribution::integration('inferred-handler'),
    );

    $example = $draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null;

    // A plain list takes the `type: [array, null]` form instead, and still renders one element.
    expect($example)->toBe(['hint' => ['text' => 'string'], 'codes' => ['string']]);
});

it('falls back to the required members when no construction was seen at all', function (): void {
    // No member map: the schema is the only source, so the example is the required members and nothing
    // else — the same guaranteed-body fill an array-shape payload gets.
    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\ProblemDocument'), 422),
        problemDocumentContext(),
        Contribution::integration('inferred-handler'),
    );

    $content = $draft?->freeze()->toArray()['content']['application/problem+json'] ?? [];

    expect($content['example'] ?? null)->toBe([
        'type' => 'string',
        'title' => 'string',
        'status' => 422,
        'detail' => 'string',
    ])
        ->and($content['schema']['$ref'] ?? null)->toBe('#/components/schemas/ProblemDocument');
});
