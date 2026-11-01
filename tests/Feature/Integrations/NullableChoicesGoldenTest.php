<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\NullableChoices\TicketController;
use Docuccino\Laravel\Tests\Fixtures\NullableChoices\TicketSummary;
use Docuccino\Laravel\Tests\Fixtures\NullableChoices\UpdateTicketRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * Closed sets that also take null — a `nullable|in:` request field, a response field typed as a literal
 * or null — locked in emitted bytes under both nullable policies. Nothing else in the golden corpus
 * publishes a value list beside a null, so nothing moved when those shapes started admitting it.
 */
function nullableChoicesBuild(string $policy): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->put('api/zz-tickets', [TicketController::class, 'update']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        classOverrides: [TicketSummary::class => (new ClassMetadataFactory)->forClass(new ClassRef(TicketSummary::class))],
        analysisOverrides: [TicketController::class.'::update' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(TicketSummary::class), new SourceLocation(''))])],
        traceOverrides: [UpdateTicketRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(UpdateTicketRequest::class))->getFileName(),
            UpdateTicketRequest::class,
            'rules',
        )],
    ));

    return generateDocument(static function (array $raw) use ($policy): array {
        $raw['representation']['nullable'] = $policy;

        return $raw;
    });
}

it('emits nullable closed sets byte-identical to their committed goldens', function (string $policy): void {
    $result = nullableChoicesBuild($policy);

    assertGolden('nullable-choices.'.$policy.'.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('nullable-choices.'.$policy.'.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
})->with(['type-array', 'anyof']);

it('accepts null in each nullable closed set, as the server does, and refuses a value outside it', function (string $policy, string $side, string $field, mixed $value, bool $accepted): void {
    // The server's answer, by Laravel itself, for the request side.
    if ($side === 'request') {
        $body = ['channel' => 'email', $field => $value];
        expect(Validator::make($body, (new UpdateTicketRequest)->rules())->passes())->toBe($accepted);
    }

    $document = json_decode((new UirEmitter)->emit(nullableChoicesBuild($policy)->document), flags: JSON_THROW_ON_ERROR);
    $schemas = $document->components->schemas;
    $schema = $side === 'request' ? $schemas->UpdateTicketRequest->properties->{$field} : $schemas->TicketSummary->properties->{$field};

    expect((new SchemaValidator)->validate(json_decode((string) json_encode($value)), $schema)->isValid())->toBe($accepted);

    // The 3.0 document owes the same answer, read as 3.0 reads it.
    $pointer = $side === 'request' ? '/components/schemas/UpdateTicketRequest/properties/'.$field : '/components/schemas/TicketSummary/properties/'.$field;
    $downlevel = (new OpenApi30DownlevelEmitter)->emit(nullableChoicesBuild($policy)->document);
    expect(openApi30Admits($downlevel, $pointer, $value))->toBe($accepted);
})->with(['type-array', 'anyof'])->with([
    'a nullable in: rule, null' => ['request', 'status', null, true],
    'a nullable in: rule, a member' => ['request', 'status', 'closed', true],
    'a nullable in: rule, an outsider' => ['request', 'status', 'pending', false],
    'a nullable Rule::in, null' => ['request', 'priority', null, true],
    'a nullable Rule::in, an outsider' => ['request', 'priority', 'urgent', false],
    'a literal or null, null' => ['response', 'flag', null, true],
    'a literal or null, the literal' => ['response', 'flag', 'escalated', true],
    'a literal or null, another value' => ['response', 'flag', 'calm', false],
    'two literals or null, null' => ['response', 'state', null, true],
    'two literals or null, an outsider' => ['response', 'state', 'pending', false],
]);

/*
 * An authored example beside a nullable union is a value for the union as a whole. Where the null is an
 * `anyOf` branch the member states no `type` of its own, and reading the text against that absent `type`
 * published `@example null` as the string "null" and `@example 3` as the string "3" — neither of them a
 * value the member accepts, in either OpenAPI version.
 */
it('publishes each authored example as a value its member accepts, in 3.0 as in 3.1', function (string $policy, string $field, mixed $expected, bool $strict30): void {
    $result = nullableChoicesBuild($policy);
    $pointer = '/components/schemas/TicketSummary/properties/'.$field;

    $document = json_decode((new UirEmitter)->emit($result->document), flags: JSON_THROW_ON_ERROR);
    $member = $document->components->schemas->TicketSummary->properties->{$field};

    expect(property_exists($member, 'example'))->toBeTrue()
        ->and($member->example)->toBe($expected);

    // Held to the member by a validator, `$ref` resolved through the document it stands in.
    $document->{'$ref'} = '#'.$pointer;
    expect((new SchemaValidator)->validate(json_decode((string) json_encode($expected)), $document)->isValid())->toBeTrue();

    $downlevel = (new OpenApi30DownlevelEmitter)->emit($result->document);
    $member30 = json_decode($downlevel, true)['components']['schemas']['TicketSummary']['properties'][$field];

    expect($member30['example'])->toBe($expected)
        ->and($member30['nullable'] ?? false)->toBeTrue()
        // A nullable reference downlevels to `allOf: [$ref]` beside `nullable: true`, which 3.0 generators
        // read as nullable and a strict 3.0.3 validator does not — the emitter's own trade-off, which it
        // reports as `downlevel.nullable-composition`. Everywhere else 3.0 admits the example outright.
        ->and(openApi30Admits($downlevel, $pointer, $expected))->toBe($strict30);
})->with(['type-array', 'anyof'])->with([
    'null beside a nullable enum' => ['queue', null, false],
    'a number beside a nullable integer' => ['replies', 3, true],
]);
