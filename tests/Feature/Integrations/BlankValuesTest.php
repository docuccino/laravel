<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Document\BlankAsNull;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\BlankString;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator;

/*
 * Laravel's default middleware trims a request string and turns the empty one into null before any rule
 * runs, so a blank is taken exactly where a null is — by a nullable field no `required`, `filled`,
 * `accepted`, `declined` or `missing` holds to account — and refused wherever a null is. The document says
 * so as a fact beside the field's unchanged schema, and the contract checker reads a blank there as the
 * null the server reads. The server is the oracle, reached over HTTP so the middleware runs, and the check
 * reads the very request it answered: a JSON body, a form, and a query string.
 */

beforeEach(function (): void {
    // The field's published schema, under one nullable policy.
    $this->schema = static function (string $name, string $rules, string $policy = 'type-array'): array {
        $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy(nullable: $policy));

        /** @var array<string, mixed> $field */
        $field = validationSchema(new RuleSet([$name => RuleParsing::tokens($rules)]), $context)['properties'][$name];

        return $field;
    };

    // A contract holding the field as a query parameter and as a member of a JSON or form body.
    $this->contract = function (string $name, string $rules, string $policy = 'type-array'): ContractIndex {
        $field = ($this->schema)($name, $rules, $policy);
        $body = ['schema' => ['type' => 'object', 'properties' => [$name => $field]]];
        $responses = ['204' => ['description' => 'Taken'], '422' => ['description' => 'Refused']];

        return ContractIndex::fromArray([
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/zz-blank' => [
                'get' => ['parameters' => [['name' => $name, 'in' => 'query', 'schema' => $field]], 'responses' => $responses],
                'post' => ['requestBody' => ['content' => [
                    'application/json' => $body,
                    'application/x-www-form-urlencoded' => $body,
                    'multipart/form-data' => $body,
                ]], 'responses' => $responses],
            ]],
        ]);
    };

    // Whether the checker passes the request the response answered, read exactly as the assertions read it.
    $this->checks = static function (TestResponse $response, ContractIndex $index): bool {
        $exchange = ApiContract::exchangeFor($response->baseRequest, $response, false);

        return (new ContractChecker($index))->check($exchange, true, false)->request?->violations === [];
    };

    $this->route = static function (string $uri, string $name, string $rules): void {
        $handler = static function (Request $request) use ($name, $rules): Response {
            $request->validate([$name => explode('|', $rules)]);

            return response()->noContent();
        };

        Route::get($uri, $handler);
        Route::post($uri, $handler);
    };

    // One request to the route, as a client sends it at each site.
    $this->send = function (string $site, string $name, string $value): TestResponse {
        return match ($site) {
            'json' => $this->postJson('zz-blank', [$name => $value]),
            'form' => $this->post('zz-blank', [$name => $value]),
            'query' => $this->get('zz-blank?'.rawurlencode($name).'='.rawurlencode($value)),
        };
    };
});

// Every blank the default middleware clears: nothing, spaces, a control character, a no-break space and
// the invisible characters `Str::trim()` strips too.
dataset('blanks', [
    'empty' => [''],
    'spaces' => ['   '],
    'a tab' => ["\t"],
    'a no-break space' => ["\u{A0}"],
    'invisible characters' => ["\u{200B}\u{FEFF}"],
]);

dataset('sites', ['a JSON body' => ['json'], 'a form' => ['form'], 'the query string' => ['query']]);

dataset('blank rule sets', [
    // Taken: the blank arrives as the null the field accepts, whatever else the rules say of a value.
    'a nullable pattern' => ['nullable|regex:/^[a-z]+$/', true],
    'a nullable format' => ['nullable|uuid', true],
    'a nullable minimum length' => ['nullable|string|min:3', true],
    'a nullable maximum length' => ['nullable|string|max:5', true],
    'a nullable value list' => ['nullable|in:open,closed', true],
    'a nullable integer' => ['nullable|integer|min:1', true],
    'a nullable number' => ['nullable|numeric', true],
    'a nullable boolean' => ['nullable|boolean', true],
    'a nullable date' => ['nullable|date', true],
    'a nullable email' => ['nullable|email', true],
    'a nullable list' => ['nullable|array', true],
    'present and nullable' => ['present|nullable|uuid', true],
    'sometimes and nullable' => ['sometimes|nullable|uuid', true],
    // A condition that does not hold asks nothing of the value, so the blank is taken while it does not.
    'nullable and conditionally required' => ['nullable|required_if:other,1|uuid', true],
    'nullable and conditionally accepted' => ['nullable|accepted_if:other,1', true],
    // Refused: each holds a blank, and the null it becomes, to account.
    'required' => ['required|nullable|uuid', false],
    'filled' => ['filled|nullable|uuid', false],
    'accepted' => ['nullable|accepted', false],
    'declined' => ['nullable|declined', false],
    // Refused: the null the blank becomes is not a value these take.
    'an optional format' => ['sometimes|uuid', false],
    'an optional value list' => ['in:open,closed', false],
    'an optional integer' => ['integer', false],
    'an optional pattern' => ['regex:/^[a-z]+$/', false],
]);

it('passes a blank exactly where the server takes one', function (string $rules, bool $taken, string $blank, string $site): void {
    ($this->route)('zz-blank', 'field', $rules);

    $response = ($this->send)($site, 'field', $blank);

    // The premise: the row states what the server does, rather than what the document is hoped to say.
    expect($response->status() === 204)->toBe($taken)
        ->and(($this->checks)($response, ($this->contract)('field', $rules)))->toBe($taken);
})->with('blank rule sets')->with('blanks')->with('sites');

it('states the reading beside a schema that is exactly what it was, under either nullable policy', function (string $rules, bool $taken, string $policy): void {
    $schema = ($this->schema)('field', $rules, $policy);
    $fact = BlankAsNull::of($schema);
    unset($schema['x-docuccino']);

    // The fact is the whole of the change: the schema without it is the field's own, and takes no blank.
    expect($fact)->toBe($taken ? BlankString::PATTERN : null)
        ->and(json_encode($schema))->not->toContain('x-docuccino');
})->with('blank rule sets')->with(['type-array', 'anyof']);

it('holds a value that is not blank to the field, as ever', function (string $rules, string $sent, bool $taken, string $site): void {
    ($this->route)('zz-blank', 'field', $rules);

    $response = ($this->send)($site, 'field', $sent);

    expect($response->status() === 204)->toBe($taken)
        ->and(($this->checks)($response, ($this->contract)('field', $rules)))->toBe($taken);
})->with([
    'an integer' => ['nullable|integer|min:1', '42', true],
    'a word beside an integer' => ['nullable|integer|min:1', 'abc', false],
    'a member' => ['nullable|in:open,closed', 'open', true],
    'an outsider' => ['nullable|in:open,closed', 'gone', false],
    'a format' => ['nullable|uuid', '3fa85f64-5717-4562-b3fc-2c963f66afa6', true],
    'a non-uuid' => ['nullable|uuid', 'not-a-uuid', false],
])->with(['a form' => ['form'], 'the query string' => ['query']]);

it('reads only the empty string as null at a key TrimStrings leaves untrimmed', function (string $blank, bool $server, bool $checker, string $site): void {
    // `password` keeps its whitespace, so only `""` becomes the null. A whitespace-only one reaches the rules
    // as a string, and Laravel skips a string `trim()` empties — which the checker does not model: it holds
    // one to the field's schema, the narrower answer.
    ($this->route)('zz-blank', 'password', 'nullable|string|min:8');

    $response = ($this->send)($site, 'password', $blank);

    expect(BlankString::untrimmed())->toContain('password')
        ->and(BlankAsNull::of(($this->schema)('password', 'nullable|string|min:8')))->toBe(BlankString::EMPTY)
        ->and($response->status() === 204)->toBe($server)
        ->and(($this->checks)($response, ($this->contract)('password', 'nullable|string|min:8')))->toBe($checker);
})->with([
    'empty' => ['', true, true],
    'spaces' => ['   ', true, false],
    'a no-break space' => ["\u{A0}", false, false],
])->with('sites');

it('checks a query value as the client sent it, not as the middleware left it', function (): void {
    ($this->route)('zz-blank', 'field', 'sometimes|uuid');

    $response = $this->getJson('zz-blank?field=');

    // The premise: the bag no longer holds what was sent — read late, `?field=` was a missing parameter.
    expect($response->baseRequest->query->all())->toBe(['field' => null])
        ->and($response->status())->toBe(422)
        ->and(($this->checks)($response, ($this->contract)('field', 'sometimes|uuid')))->toBeFalse();
});

it('matches exactly the characters Laravel counts as blank', function (): void {
    // Read off the framework rather than off the list, so a character Laravel starts clearing fails here.
    // `Str::trim()` is what the default middleware clears with; `trim()` is what the validator skips on,
    // and every one of its characters is one of `Str::trim()`'s.
    $laravel = [];
    $trimmed = [];
    $published = [];
    for ($code = 0; $code <= 0x10FFFF; $code++) {
        if ($code >= 0xD800 && $code <= 0xDFFF) {
            continue;
        }
        $character = (string) mb_chr($code, 'UTF-8');
        if (Str::trim($character) === '') {
            $laravel[] = $code;
        }
        if (trim($character) === '') {
            $trimmed[] = $code;
        }
        if (BlankAsNull::matches(BlankString::PATTERN, $character)) {
            $published[] = $code;
        }
    }

    // A scan that stopped seeing its characters must fail rather than agree on nothing.
    expect(count($laravel))->toBeGreaterThan(60)
        ->and($published)->toBe($laravel)
        ->and(array_diff($trimmed, $laravel))->toBe([])
        ->and(BlankAsNull::matches(BlankString::PATTERN, " \t\u{A0}\u{3000}"))->toBeTrue()
        ->and(BlankAsNull::matches(BlankString::PATTERN, ' a '))->toBeFalse();
});

/*
 * Laravel's implicit rules are the only ones that run on the null a blank becomes, so they are the whole of
 * the refusing set. Each is asked of the server with any condition it names left unmet — the rule then
 * answers for itself alone — and the document must state the reading exactly where the server takes the
 * blank. The list is read off the installed validator, so a rule Laravel adds needs a row here.
 */
it('derives the refusing rules from the validator Laravel installed', function (): void {
    /** @var list<string> $implicit */
    $implicit = (new ReflectionProperty(Validator::class, 'implicitRules'))->getDefaultValue();

    // The parameters, and the `other` value, that leave each rule's condition unmet.
    $unmet = [
        'accepted' => ['', null], 'accepted_if' => [':other,1', null], 'declined' => ['', null],
        'declined_if' => [':other,1', null], 'filled' => ['', null], 'missing' => ['', null],
        'missing_if' => [':other,1', null], 'missing_unless' => [':other,1', '1'], 'missing_with' => [':other', null],
        'missing_with_all' => [':other', null], 'present' => ['', null], 'present_if' => [':other,1', null],
        'present_unless' => [':other,1', '1'], 'present_with' => [':other', null], 'present_with_all' => [':other', null],
        'required' => ['', null], 'required_if' => [':other,1', null], 'required_if_accepted' => [':other', null],
        'required_if_declined' => [':other', null], 'required_unless' => [':other,1', '1'],
        'required_with' => [':other', null], 'required_with_all' => [':other', null],
        'required_without' => [':other', 'x'], 'required_without_all' => [':other', 'x'],
    ];

    // Implicit rules the vocabulary does not read: the field stays as permissive as the rule leaves it, and
    // the blank with it — the rule-unhandled diagnostic is what the author sees.
    $unread = ['missing', 'missing_if', 'missing_unless', 'missing_with', 'missing_with_all', 'present_if',
        'present_unless', 'present_with', 'present_with_all', 'required_if_accepted', 'required_if_declined'];

    $refusing = [];
    foreach ($implicit as $studly) {
        $rule = Str::snake($studly);
        expect(array_key_exists($rule, $unmet))->toBeTrue($rule.' is implicit in the installed Laravel and has no row here');
        [$parameters, $other] = $unmet[$rule];

        $rules = 'nullable|'.$rule.$parameters;
        Route::post('zz-blank-'.$rule, static function (Request $request) use ($rules): Response {
            $request->validate(['field' => explode('|', $rules)]);

            return response()->noContent();
        });
        $taken = $this->postJson('zz-blank-'.$rule, ['field' => '', ...($other === null ? [] : ['other' => $other])])->status() === 204;

        if (! $taken) {
            $refusing[] = $rule;
        }

        $handled = false;
        foreach (ValidationIntegration::transformers() as $transformer) {
            $handled = $handled || $transformer->supports(ValidationRule::of($rule));
        }

        if (! $handled) {
            expect($rule)->toBeIn($unread, $rule.' is read by the vocabulary now, so the document owes it an answer');

            continue;
        }

        expect(in_array($rule, $unread, true))->toBeFalse($rule.' is listed as unread')
            ->and(BlankAsNull::of(($this->schema)('field', $rules)))->toBe($taken ? BlankString::PATTERN : null, $rules);
    }

    sort($refusing);

    expect(count($implicit))->toBeGreaterThanOrEqual(20)
        ->and($refusing)->toBe(BlankString::REFUSED_BY);
});
