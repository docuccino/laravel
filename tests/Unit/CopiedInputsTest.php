<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\CopiedInputParameters;
use Docuccino\Laravel\Integrations\FormRequest\CopiedInputs;
use Docuccino\Laravel\Integrations\FormRequest\NullRejection;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AliasedRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\BagWriteRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\BaseCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CertainCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ConstructedHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerClassRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerKeyRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerMakeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\EarlyReturnRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\FacadeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\GlobalRequestRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HandedBagRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HelperCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\InheritedCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OffsetWriteRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OtherServiceRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnContainerRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnStaticCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidationDataRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ParentCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\RepeatedCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ReplacedInputRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ResolvedRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SafeUsesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ServiceHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SpreadMergeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\StaticHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\TappedHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreDialledNoticeRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreRoutedNoticeRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Workbench\App\Enums\WidgetStatus;

/**
 * `merge()` in `prepareForValidation()` writes over whatever the body sent, so a key it fills from a header,
 * a query value or a route parameter validates THAT part of the request. The reader trusts only a copy
 * nothing else in the hook can write, because a key it moves off the body on a guess is a field a client is
 * told not to send when the server may read it.
 */
function copiedInputContext(): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], '/api/notes'),
        actionRef: new ActionRef('notes.php', 'NoteController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

it('reads a copy from each part of the request by the literal name it is read under', function (): void {
    // `header('X-Locale', 'en')` answers its default when the header is absent, so the key is not what the
    // header sent; a dynamic, nested or non-`$this` read, and a transformed one, name no one parameter.
    expect((new CopiedInputs)->of(copiedInputContext(), CertainCopiesRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
        'page' => ['in' => 'query', 'name' => 'page'],
        'post' => ['in' => 'path', 'name' => 'post'],
        'trace' => ['in' => 'header', 'name' => 'X-Trace-Id'],
    ]);
});

it('keeps a copy beside a merge into a service that is not the request', function (): void {
    expect((new CopiedInputs)->of(copiedInputContext(), OtherServiceRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('trusts only a copy no other write can reach', function (): void {
    // A second merge, a fill-if-missing, a branch and a closure each write their key on some requests.
    expect((new CopiedInputs)->of(copiedInputContext(), RepeatedCopiesRequest::class))->toBe([
        'kept' => ['in' => 'header', 'name' => 'X-Kept'],
    ]);
});

it('keeps a copy beside uses of the request that hand it nowhere', function (): void {
    // A value read off it, its class, `isset()`: none of these reaches code that could merge over the copy.
    expect((new CopiedInputs)->of(copiedInputContext(), SafeUsesRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('trusts no copy where something writes keys it cannot name', function (string $class): void {
    expect((new CopiedInputs)->of(copiedInputContext(), $class))->toBe([]);
})->with([
    'an early return' => [EarlyReturnRequest::class],
    'replace()' => [ReplacedInputRequest::class],
    "a call into the request's own code" => [HelperCallRequest::class],
    'a write into a bag' => [BagWriteRequest::class],
    'an array-access write' => [OffsetWriteRequest::class],
    'a spread merge' => [SpreadMergeRequest::class],
    "the base's hook, run by parent::" => [ParentCallRequest::class],
    // Code handed the request, or one of its input bags, can merge over the copy after it.
    'itself handed to a static helper' => [StaticHandOffRequest::class],
    'itself handed to a function' => [TappedHandOffRequest::class],
    'itself handed to a service' => [ServiceHandOffRequest::class],
    'itself handed to a constructor' => [ConstructedHandOffRequest::class],
    'itself held in a variable' => [AliasedRequest::class],
    'its JSON bag handed on' => [HandedBagRequest::class],
    'its own code, called through $this::' => [OwnStaticCallRequest::class],
    // The request it was built from shares its JSON bag.
    'a merge through request()' => [GlobalRequestRequest::class],
    'a merge through the facade' => [FacadeRequest::class],
    // …and so does every way of asking the container for it.
    "a merge through app('request')" => [ContainerKeyRequest::class],
    "a merge through resolve('request')" => [ResolvedRequest::class],
    'a merge through app(Request::class)' => [ContainerClassRequest::class],
    "a merge through app()->make() of Symfony's request" => [ContainerMakeRequest::class],
    'a merge through its own container' => [OwnContainerRequest::class],
    'its own validationData()' => [OwnValidationDataRequest::class],
    'no hook at all' => [FormRequest::class],
    'a class that is no FormRequest' => [stdClass::class],
]);

it('reads an inherited hook in the class that declares it, and keys the route on that file', function (): void {
    $context = copiedInputContext();

    expect((new CopiedInputs)->of($context, InheritedCopiesRequest::class))->toBe(['key' => ['in' => 'header', 'name' => 'Idempotency-Key']])
        ->and($context->dependencyFiles())->toContain((string) (new ReflectionClass(BaseCopiesRequest::class))->getFileName());
});

it('moves a copied key only where the rules name it as one plain value', function (): void {
    $rules = new RuleSet([
        'key' => [ValidationRule::of('required'), ValidationRule::of('uuid')],
        'tags' => [ValidationRule::of('array')],
        'meta' => [ValidationRule::of('required')],
        'meta.id' => [ValidationRule::of('integer')],
        'note' => [ValidationRule::of('string')],
    ]);
    $header = static fn (string $name): array => ['in' => 'header', 'name' => $name];

    // `tags` is a container and `meta` has a member: neither is one header or query value, so both stay.
    // `unvalidated` has no rules to move, and `note` is not copied at all.
    expect(CopiedInputs::movable($rules, [
        'key' => $header('Idempotency-Key'),
        'meta' => $header('X-Meta'),
        'tags' => $header('X-Tags'),
        'unvalidated' => $header('X-Unvalidated'),
    ]))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key', 'rules' => $rules->fields['key']],
    ]);
});

it('publishes on a parameter exactly the rules the body gave up, and nothing it did not', function (): void {
    $context = copiedInputContext();
    $rules = new RuleSet([
        'key' => [ValidationRule::of('required'), ValidationRule::of('uuid')],
        'page' => [ValidationRule::of('nullable'), ValidationRule::of('integer')],
        'title' => [ValidationRule::of('required'), ValidationRule::of('string')],
    ]);

    // Nothing left the body, so a parameter has nothing to carry — whatever the FormRequest copies.
    $untouched = new OperationDraft;
    $untouched->parameter('header', 'idempotency-key');
    (new CopiedInputParameters)->handle($untouched, $context);
    expect($untouched->parameterKeys())->toBe(['header:idempotency-key'])
        ->and($untouched->parameter('header', 'idempotency-key')->schema()->saysNothingAboutTheInstance())->toBeTrue()
        ->and($untouched->parameter('header', 'idempotency-key')->resolvedField('required'))->toBeNull();

    $operation = new OperationDraft;
    $operation->parameter('header', 'idempotency-key');
    $kept = (new CopiedInputs)->move($operation, $context, CertainCopiesRequest::class, $rules);
    (new CopiedInputParameters)->handle($operation, $context);

    // The body keeps what the client sends; the header, found under the case the read published it by,
    // and the query value carry the rules that left it (their schemas are the feature test's to pin).
    expect(array_keys($kept->fields))->toBe(['title'])
        ->and(array_keys(CopiedInputs::movedFrom($operation)))->toBe(['key', 'page'])
        ->and($operation->parameterKeys())->toBe(['header:idempotency-key', 'query:page'])
        ->and($operation->parameter('header', 'idempotency-key')->resolvedField('required'))->toBeTrue()
        ->and($operation->parameter('header', 'idempotency-key')->schema()->resolvedField('type'))->toBe('string')
        ->and($operation->parameter('query', 'page')->resolvedField('required'))->toBeFalse();
});

it('moves a key a partition is read off with the rules it has where no partition is proved', function (string $class, string $key): void {
    $rules = new RuleSet(array_map(RuleParsing::tokens(...), [
        'channel' => 'required|in:email,sms',
        'address' => 'exclude_unless:channel,email|required|email',
        'phone' => 'exclude_unless:channel,sms|required|string|max:20',
        'body' => 'required|string|max:500',
    ]));
    $normalizer = new RuleSetNormalizer;
    $plain = $normalizer->normalize($rules);
    $operation = new OperationDraft;

    $kept = (new CopiedInputs)->move($operation, copiedInputContext(), $class, $normalizer->normalize($rules, true));

    // The split had moved the tag's `required` and the member's gated `required` onto the partition; the
    // parameter publishes what the server validates the copied value by, and the body is given up whole.
    expect(CopiedInputs::movedFrom($operation)[$key]['rules'])->toEqual($plain->fields[$key])
        ->and($kept->variants)->toBe([])
        ->and($kept->fields)->toEqual(array_diff_key($plain->fields, [$key => true]));
})->with([
    'the tag' => [StoreRoutedNoticeRequest::class, 'channel'],
    'a member the tag switches' => [StoreDialledNoticeRequest::class, 'phone'],
]);

/*
 * "Required" is exactly "the validator fails a present null", since a copied key holds null when what it
 * copies was not sent. So every rule asked is checked against the application's own validator, spelled the
 * way an application writes it, alone and beside `nullable`; one this version does not have is refused
 * there, and adds no claim here.
 */
it('names a rule as refusing a null exactly where the installed validator fails one', function (string|object $rule): void {
    $name = is_string($rule) ? explode(':', $rule)[0] : 'enum';
    $parsed = is_string($rule)
        ? ValidationRule::of($name, str_contains($rule, ':') ? str_getcsv(explode(':', $rule, 2)[1], escape: '\\') : [])
        : ValidationRule::of('enum', array_map(static fn (WidgetStatus $case): string => $case->value, WidgetStatus::cases()), WidgetStatus::class);
    $validatorFails = static function (array $rules): bool {
        try {
            return Validator::make(['k' => null], ['k' => $rules])->fails();
        } catch (BadMethodCallException) {
            return false;
        }
    };

    expect(NullRejection::rejects([$parsed]))->toBe($validatorFails([$rule]))
        ->and(NullRejection::rejects([ValidationRule::of('nullable'), $parsed]))->toBe($validatorFails(['nullable', $rule]));
})->with(function (): array {
    $samples = [
        'array_keys' => 'array_keys:a', 'between' => 'between:0,4', 'contains' => 'contains:a', 'date_format' => 'date_format:Y-m-d',
        'decimal' => 'decimal:0,2', 'digits' => 'digits:3', 'digits_between' => 'digits_between:0,4', 'dimensions' => 'dimensions:min_width=1',
        'doesnt_contain' => 'doesnt_contain:a', 'doesnt_end_with' => 'doesnt_end_with:x', 'doesnt_start_with' => 'doesnt_start_with:x',
        'encoding' => 'encoding:UTF-8', 'ends_with' => 'ends_with:x', 'extensions' => 'extensions:png', 'in' => 'in:web,mobile',
        'in_array_keys' => 'in_array_keys:a', 'max' => 'max:36', 'max_digits' => 'max_digits:3', 'mimes' => 'mimes:png',
        'mimetypes' => 'mimetypes:image/png', 'min' => 'min:0', 'min_digits' => 'min_digits:0', 'multiple_of' => 'multiple_of:2',
        'not_in' => 'not_in:web,mobile', 'not_regex' => 'not_regex:/^a$/', 'regex' => 'regex:/^a$/',
        'required_array_keys' => 'required_array_keys:a', 'size' => 'size:0', 'starts_with' => 'starts_with:x',
    ];

    $rows = ['enum' => [Rule::enum(WidgetStatus::class)]];
    foreach (NullRejection::ASKED as $name) {
        $rows[$name] = [$samples[$name] ?? $name];
    }

    return $rows;
});

/*
 * The same answers stated from the contract rather than asked for, so the check above cannot pass by the two
 * sides agreeing on a wrong spelling. Laravel 13 type-checks the value before `ascii` and the digit and
 * prefix rules, where 12 hands a null on and lets it through; `base64` is a rule 13 added.
 */
it('names a rule set as refusing a null exactly where the installed Laravel does', function (string $rules, bool $on12, bool $on13): void {
    $parsed = array_map(static function (string $rule): ValidationRule {
        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);

        return ValidationRule::of($name, $parameters === null ? [] : explode(',', $parameters));
    }, explode('|', $rules));

    expect(NullRejection::rejects($parsed))->toBe((int) explode('.', Application::VERSION)[0] >= 13 ? $on13 : $on12);
})->with([
    'nullable' => ['nullable|uuid', false, false],
    'a bound alone' => ['max:36', false, false],
    'present' => ['present', false, false],
    'a form rule' => ['uuid', true, true],
    'a condition that does not hold, with nullable' => ['required_if:other,yes|nullable|string', false, false],
    'a condition beside a form rule' => ['required_if:other,yes|string', true, true],
    'an empty string among the choices' => ['in:,web', false, false],
    'ascii' => ['ascii', false, true],
    'no digits' => ['digits_between:0,4', false, true],
    'a digit bound' => ['max_digits:3', false, true],
    'a refused prefix' => ['doesnt_start_with:x', false, true],
    'a refused suffix' => ['doesnt_end_with:x', false, true],
    'a rule only 13 has' => ['base64', false, true],
    // Never asked: it needs a database, and a build may not query one.
    'a store' => ['exists:users,id', false, false],
]);

it('adds no claim for a rule it cannot ask, and one the validator refuses leaves the others standing', function (): void {
    expect(NullRejection::rejects([ValidationRule::of('object')]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('email', ['dns'])]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('enum', ['a'], 'NotAnEnum')]))->toBeFalse()
        // `digits_between` needs two parameters, so the validator refuses it, and `required` still answers.
        ->and(NullRejection::rejects([ValidationRule::of('digits_between')]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('digits_between'), ValidationRule::of('required')]))->toBeTrue();
});

it('classes every rule the installed validator has as asked or not, and says why for each it does not ask', function (): void {
    $rules = [];
    foreach ((new ReflectionClass(ValidatorInstance::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (preg_match('/^validate[A-Z]/', $method->name) === 1) {
            $rules[] = Str::snake(substr($method->name, 8));
        }
    }

    expect(count($rules))->toBeGreaterThan(100)
        ->and(array_values(array_diff($rules, NullRejection::ASKED, array_keys(NullRejection::NOT_ASKED))))->toBe([])
        ->and(array_values(array_intersect(NullRejection::ASKED, array_keys(NullRejection::NOT_ASKED))))->toBe([]);
});

it('publishes a part optional where the rules only exclude it on a condition', function (): void {
    // Whether `exclude_if` drops the key is read at runtime, so neither answer is certain and the wider
    // one — optional — is what cannot mark a working request invalid.
    expect(NullRejection::rejects([ValidationRule::of('exclude_if', ['mode', 'draft']), ValidationRule::of('required')]))->toBeFalse();
});
