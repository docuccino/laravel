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
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
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
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerResponseBuilder;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportFailure;

/**
 * The example an inferred handler response carries has to be a valid instance of the schema beside it. Only
 * literal members fold in, so a body requiring members that stayed widened — an RFC 9457 problem document
 * folds `type` and `status` but not `title`/`detail`/`instance` — has the rest filled from the schema:
 * type-derived placeholders, plus the real response status. The fill is confined to examples, which are
 * illustrative by definition; nothing invented ever reaches a schema.
 *
 * When the body is an object the engine watched being constructed, the arguments it was built with ADD to
 * membership rather than replacing the schema's: supplied beats optional (this branch passed it, so this
 * response has it), and a member the schema REQUIRES is illustrated whether the branch passed it or not,
 * since an example missing one would fail against the schema printed beside it ('still fills a required
 * member no argument accounted for' is that guard). Only an unsupplied optional member is left out — and
 * an argument that renders the key only on some responses decides nothing, so the schema answers for it.
 */
/**
 * The throw the tier is answering for. Only its status hint and its FQCN reach the builder: the hint is
 * the last reading available when neither side of the render path folded one, and the FQCN is what the
 * builder classifies with when there is no reading at all.
 */
function handlerThrow(?int $hint, string $fqcn = 'App\\Exceptions\\ProbeFailure'): ThrownException
{
    return new ThrownException($fqcn, $hint, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

function handlerContext(?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
        ),
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)->toBe([
        'type' => 'about:blank',
        'title' => 'Unprocessable Content',
        'status' => 422,
        'detail' => 'string',
    ]);
});

it('illustrates an unread member from the value domain its schema states', function (): void {
    // An example is an instance of the schema beside it, and where that schema NAMES the values the member
    // may hold, `"string"` is not one of them: a consumer copying the example would send a body the server
    // refuses, and the build's own example lint would report a mismatch its reader never wrote and cannot
    // correct. Which entry is settled in advance rather than by encounter order — the first, because a
    // list's order is authored and every other reader of the document shows that same branch.
    //
    // The member is still a FILL: nothing about this response was read, only the schema next to it. So it
    // stays in the record, and a reader of the record still knows this arm never proved the value.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\ExportProblem' => new ClassMetadata('App\\Data\\ExportProblem', [
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('reason', new EnumT(ExportFailure::class, ['QuotaExceeded', 'SourceUnavailable'])),
        ]),
    ]));

    $draft = HandlerResponseBuilder::build(
        handlerAnalysis(
            new ClassT('App\\Data\\ExportProblem'),
            409,
            suppliedMembers(['status' => 409, 'reason' => null]),
        ),
        $context,
        Contribution::integration('inferred-handler'),
        handlerThrow(409),
        'App\\Exceptions\\Handler::render',
    );

    $frozen = $draft?->freeze()->toArray() ?? [];

    // The case NAMES, because this context carries only core's case-names enum mapper. What the fill reads
    // is the schema the document actually published, never the type behind it — an application whose
    // reflection-rich mapper publishes backing values gets those instead, and neither is second-guessed.
    expect($frozen['content']['application/problem+json']['example'] ?? null)
        ->toBe(['status' => 409, 'reason' => 'QuotaExceeded'])
        ->and($frozen['x-docuccino']['facts']['examplePlaceholders'] ?? null)
        ->toBe(['application/problem+json' => ['reason']]);
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(500),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(500),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
    );

    expect($draft?->freeze()->toArray()['content']['application/problem+json']['example'] ?? null)->toBe([
        'type' => 'about:blank',
        'title' => 'Unprocessable Content',
        'detail' => 'pinned',
    ]);
});

it('fills a member from the bound its schema carries, and still calls that member unread', function (): void {
    // A bound is the one CONSTRAINT that also names a legal value, which is why it is read where a
    // `pattern` is not: `0` is a value `minimum: 5` rejects, and a filled member the schema next to it
    // rejects is a body the server refuses AND a `lint.example-mismatch` against an example its reader
    // never wrote. The answers here come from the keywords' own meaning — 5 clears a floor of 5, 1 is the
    // nearest integer above an exclusive 0, and 10 is the first multiple of 10 at or above a floor of 1.
    //
    // Then the second half, which a better fill could quietly lose: every one of them is still recorded as
    // a member NOTHING READ. `5` reads exactly like a value a server sends, and the record is the only
    // thing that can tell those apart downstream — a collapse consulting it would otherwise treat this arm
    // as having read the member and drop a rival illustration that had actually proved it.
    $context = handlerContext(new StubTypeEngine(classes: [
        'App\\Data\\BoundedProblem' => new ClassMetadata('App\\Data\\BoundedProblem', [
            new PropertyMetadata('status', ScalarT::int()),
            new PropertyMetadata('attempt', ScalarT::int()),
            new PropertyMetadata('backoff', ScalarT::int()),
            new PropertyMetadata('quota', ScalarT::int()),
        ]),
    ]));

    // The component as the document publishes it — a hand-authored one, since no type mapper mints a
    // bound: the fill reads whatever the finished component says, wherever that came from.
    $context->components->registerSchema('BoundedProblem', [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'integer'],
            'attempt' => ['type' => 'integer', 'exclusiveMinimum' => 0],
            'backoff' => ['type' => 'integer', 'minimum' => 1, 'multipleOf' => 10],
            'quota' => ['type' => 'integer', 'minimum' => 5],
        ],
        'required' => ['status', 'attempt', 'backoff', 'quota'],
    ], 'App\\Data\\BoundedProblem');

    $frozen = HandlerResponseBuilder::build(
        handlerAnalysis(new ClassT('App\\Data\\BoundedProblem'), 429),
        $context,
        Contribution::integration('inferred-handler'),
        handlerThrow(429),
        'App\\Exceptions\\Handler::render',
    )?->freeze()->toArray() ?? [];

    expect($frozen['content']['application/problem+json']['example'] ?? null)->toBe([
        // Unbounded and named `status`, so it is the one member here that is not a fill at all: it is the
        // status this response really answers with.
        'status' => 429,
        'attempt' => 1,
        'backoff' => 10,
        'quota' => 5,
    ])->and($frozen['x-docuccino']['facts']['examplePlaceholders'] ?? null)
        ->toBe(['application/problem+json' => ['attempt', 'backoff', 'quota']]);
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
        handlerThrow(422),
        'App\\Exceptions\\Handler::render',
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
