<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\CustomRuleRequest;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\DeclaredRulesData;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\SuppressibleRulesData;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;
use Workbench\App\Http\Controllers\InlineValidationController;

/**
 * A rules recoverer says what became of a field it could not read, and both sentences are claims about
 * the document — the field is omitted, a constraint of it was left off. A declaration writes that field
 * at a layer above the recovery, so a recoverer that speaks without reading the declarations asserts
 * what a later layer has already decided, and names a remedy the author went around.
 *
 * Which declarations those are is a function of where the rules LAND: a body verb's become a request
 * body that `#[BodyParameter]` patches, a read verb's become query parameters that `#[QueryParameter]`
 * patches. The two layers are not interchangeable, and the rows below measure both — including the
 * location word, which has to name the half of the document the field was really lost from.
 */

/** Two unreadable rules, one of which the cases declare and one of which nothing ever does. */
const UNREADABLE_RULES_PAIR = "return ['file' => [fn () => true], 'secret' => [fn () => true]];";

it('names the field a declaration does not answer for, and only that field', function (array $declarations, string $class, string $rules, string $verb, string $location, array $named): void {
    $context = validationRulesContext([$class.'::rules' => $rules], $declarations, $verb);

    (new RulesFromClass)->analyse($context, $class);

    $messages = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        diagnosticsCoded($context->components->diagnostics(), 'validation.rule-unrecoverable'),
    );

    expect($messages)->toHaveCount(count($named));

    foreach ($named as $index => $field) {
        expect($messages[$index])->toContain('"'.$field.'"')->toContain('omitted from '.$location);
    }
})->with([
    // A declaration naming the field is written OVER the property, so what is published there is the
    // author's whole and no recovered rule would have survived beside it.
    'at the field' => [[new BodyParameter(name: 'file', type: 'object')], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'POST', 'the request schema', ['secret']],

    // One naming a key INSIDE it publishes the field as that container — the dotted spelling, which is
    // the branch that separates the path reading from a plain name comparison.
    'inside the field' => [[new BodyParameter(name: 'file.name', type: 'string')], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'POST', 'the request schema', ['secret']],

    // And one naming a container ABOVE it decides the field too, in the other direction: the declared
    // node goes in whole, so `meta.tags` is gone whatever rules the author writes and the note's own
    // remedy cannot clear it.
    'above the field' => [
        [new BodyParameter(name: 'meta', type: 'object')],
        SuppressibleRulesData::class,
        "return ['meta' => ['array'], 'meta.tags' => [fn () => true], 'secret' => [fn () => true]];",
        'POST',
        'the request schema',
        ['secret'],
    ],

    // A declaration on another branch answers for nothing here.
    'on another branch' => [[new BodyParameter(name: 'elsewhere', type: 'object')], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'POST', 'the request schema', ['file', 'secret']],

    // The request TYPE's own declaration, which the route attribute bag never sees.
    'on the request type' => [[], DeclaredRulesData::class, UNREADABLE_RULES_PAIR, 'POST', 'the request schema', ['secret']],

    // A read verb sends the same rules to query parameters, so that is where the field was lost and
    // what the message has to say — there is no request schema on the operation at all.
    'a read verb with nothing declared' => [[], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'GET', 'the query parameters', ['file', 'secret']],

    // A `#[BodyParameter]` there patches a body nothing wrote, so it answers for no field.
    'a read verb with a body declaration' => [[new BodyParameter(name: 'file', type: 'object')], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'GET', 'the query parameters', ['file', 'secret']],

    // A `#[QueryParameter]` is the declaration that reaches there, and it publishes the parameter the
    // recovery could not.
    'a read verb with a query declaration' => [[new QueryParameter(name: 'file', type: 'string')], SuppressibleRulesData::class, UNREADABLE_RULES_PAIR, 'GET', 'the query parameters', ['secret']],

    // A nested field rides the query string as brackets, so a declaration naming it that way is the
    // same field said in the spelling the write uses.
    'a read verb with a bracketed query declaration' => [
        [new QueryParameter(name: 'filter[status]', type: 'string')],
        SuppressibleRulesData::class,
        "return ['filter.status' => [fn () => true], 'secret' => [fn () => true]];",
        'GET',
        'the query parameters',
        ['secret'],
    ],

    // And it answers for the parameter it NAMES and no other: each name mints its own parameter and
    // leaves the rest of them as the recovery left them, so nothing here was published for `file`.
    'a read verb with a query declaration inside the field' => [
        [new QueryParameter(name: 'file[name]', type: 'string')],
        SuppressibleRulesData::class,
        UNREADABLE_RULES_PAIR,
        'GET',
        'the query parameters',
        ['file', 'secret'],
    ],
]);

it('does not call an inline field omitted that a declaration puts in the body', function (): void {
    app('router')->post('api/inline-validated', [InlineValidationController::class, 'store']);

    app()->instance(TypeEngine::class, new StubTypeEngine(traces: [
        InlineValidationController::class.'::store' => RulesTraceScript::forPhp(
            "\$request->validate(['title' => 'required|string', 'payload' => [fn () => true], 'secret' => [fn () => true]]);",
        ),
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();
    $schema = $document['paths']['/api/inline-validated']['post']['requestBody']['content']['application/json']['schema'];

    // The premise: the declaration publishes `payload`, so nothing about it was omitted.
    expect(array_keys($schema['properties']))->toBe(['title', 'payload']);

    $messages = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        diagnosticsCoded($result->diagnostics, 'validation.rule-unrecoverable'),
    );

    // …and `secret`, which nobody declared, is the one the note is still about.
    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"secret"');
});

it('does not call a constraint left off that a declaration overwrites anyway', function (): void {
    $context = validationRulesContext(
        [CustomRuleRequest::class.'::rules' => <<<'PHP'
            return [
                'status' => ['required', \Illuminate\Validation\Rule::in('any', ...$this->statuses())],
                'visibility' => ['required', \Illuminate\Validation\Rule::in('draft', ...$this->hidden())],
            ];
            PHP],
        [new BodyParameter(name: 'status', type: 'string')],
    );

    (new RulesFromClass)->analyse($context, CustomRuleRequest::class);

    $messages = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        diagnosticsCoded($context->components->diagnostics(), 'validation.rule-values-unread'),
    );

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"visibility"')
        ->and($messages[0])->toContain('left off the request schema');
});
