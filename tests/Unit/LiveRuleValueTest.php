<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Docuccino\Laravel\Tests\Fixtures\Rules\RecordedUpload;
use Docuccino\Laravel\Tests\Fixtures\Rules\TenantMember;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\Unique;
use Workbench\App\Enums\WidgetStatus;

/*
 * A rule object an attribute was given already exists, so it is read back from its state rather than from
 * the `new` that built it. Each row pairs the object with the value the engine folds that same `new` to,
 * so a rule handed to `#[Rule(…)]` reads exactly as the one written in a rules array.
 */
$rules = 'Illuminate\\Validation\\Rules\\';
$case = static fn (string $name): ConstValue => ConstValue::enumCase(WidgetStatus::class, $name);
$list = static fn (ConstValue ...$items): ConstValue => ConstValue::array(array_values($items));
$string = ConstValue::scalar(...);

$objects = [
    'an enum' => [static fn () => new Enum(WidgetStatus::class), ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])],
    'an enum narrowed by only' => [
        static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft]),
        ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])->withChainedCall('only', [$list($case('Draft'))]),
    ],
    'an enum narrowed by except' => [
        static fn () => (new Enum(WidgetStatus::class))->except(WidgetStatus::Archived),
        ConstValue::instance(Enum::class, [$string(WidgetStatus::class)])->withChainedCall('except', [$case('Archived')]),
    ],
    'a choice list' => [static fn () => new In(['a', WidgetStatus::Draft]), ConstValue::instance(In::class, [$list($string('a'), $case('Draft'))])],
    'a choice list of separate arguments' => [static fn () => new In('a', 'b'), ConstValue::instance(In::class, [$string('a'), $string('b')])],
    'an exclusion list' => [static fn () => new NotIn(['x']), ConstValue::instance(NotIn::class, [$list($string('x'))])],
    'an array naming its keys' => [static fn () => new ArrayRule(['name']), ConstValue::instance(ArrayRule::class, [$list($string('name'))])],
    'a bare array' => [static fn () => new ArrayRule, ConstValue::instance(ArrayRule::class, [])],
    'a row that must exist' => [static fn () => new Exists('users', 'id'), ConstValue::instance(Exists::class, [$string('users'), $string('id')])],
    'a value that must be unique' => [static fn () => new Unique('users'), ConstValue::instance(Unique::class, [$string('users')])],
    'an exclusion that holds' => [static fn () => new ExcludeIf(true), ConstValue::instance(ExcludeIf::class, [$string(true)])],
    'an exclusion that does not' => [static fn () => new ExcludeIf(false), ConstValue::instance(ExcludeIf::class, [$string(false)])],
    'an exclusion on a closure' => [
        static fn () => new ExcludeIf(static fn (): bool => true),
        ConstValue::instance(ExcludeIf::class, [ConstValue::unknown('a closure')]),
    ],
    'an exclusion unless' => [static fn () => new ExcludeUnless(false), ConstValue::instance(ExcludeUnless::class, [$string(false)])],
    'a requirement that holds' => [static fn () => new RequiredIf(true), ConstValue::instance(RequiredIf::class, [$string(true)])],
    'a requirement on a closure' => [
        static fn () => new RequiredIf(static fn (): bool => true),
        ConstValue::instance(RequiredIf::class, [ConstValue::unknown('a closure')]),
    ],
    'a requirement unless' => [
        static fn () => new ($rules.'RequiredUnless')(false),
        ConstValue::instance($rules.'RequiredUnless', [$string(false)]),
    ],
    'a prohibition that does not hold' => [static fn () => new ProhibitedIf(false), ConstValue::instance(ProhibitedIf::class, [$string(false)])],
    'a prohibition unless' => [
        static fn () => new ($rules.'ProhibitedUnless')(true),
        ConstValue::instance($rules.'ProhibitedUnless', [$string(true)]),
    ],
    // Laravel 13's own objects for the field-presence exclusions.
    'an exclusion with a field' => [
        static fn () => new ($rules.'ExcludeWith')('other'),
        ConstValue::instance($rules.'ExcludeWith', [$string('other')]),
    ],
    'an exclusion without fields' => [
        static fn () => new ($rules.'ExcludeWithout')(['a', 'b']),
        ConstValue::instance($rules.'ExcludeWithout', [$list($string('a'), $string('b'))]),
    ],
];

it('reads a framework rule object as the new that built it', function (Closure $live, ConstValue $written): void {
    if (! class_exists((string) $written->class)) {
        $this->markTestSkipped('The installed Laravel has no '.$written->class.'.');
    }

    $read = (new ConstValueToRules)->fold(LiveRuleValue::of($live()));

    expect($read)->toEqual((new ConstValueToRules)->fold($written));
})->with($objects);

it('reads every framework object the rules-array fold reads by its arguments', function () use ($objects): void {
    // The fold's own tables are the domain; each class in them owes a row above and a reading of its state.
    $domain = [];
    foreach (['FACTORY_OBJECTS', 'CONDITIONAL_OBJECTS', 'EXCLUDE_FIELD_OBJECTS'] as $table) {
        $domain = [...$domain, ...array_keys((array) (new ReflectionClassConstant(ConstValueToRules::class, $table))->getValue())];
    }

    $rows = array_values(array_unique(array_map(static fn (array $row): string => (string) $row[1]->class, $objects)));
    $read = array_keys((array) (new ReflectionClassConstant(LiveRuleValue::class, 'ARGUMENTS'))->getValue());

    expect($domain)->toHaveCount(14)
        ->and($rows)->toEqualCanonicalizing($domain)
        ->and($read)->toEqualCanonicalizing($domain);
});

it('narrows an enum rule to the cases Laravel takes', function (Closure $rule): void {
    $values = (new ConstValueToRules)->fold(LiveRuleValue::of($rule()))[0]->parameters;

    foreach (WidgetStatus::cases() as $case) {
        $taken = Validator::make(['f' => $case->value], ['f' => [$rule()]])->passes();

        expect(in_array($case->value, $values, true))->toBe($taken, $case->value);
    }
})->with([
    'only' => [static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft, WidgetStatus::Published])],
    'except' => [static fn () => (new Enum(WidgetStatus::class))->except([WidgetStatus::Archived])],
    // Laravel consults `except` only while `only` is empty, so `only` is the whole narrowing.
    'only beside except' => [static fn () => (new Enum(WidgetStatus::class))->only([WidgetStatus::Draft, WidgetStatus::Archived])->except([WidgetStatus::Archived])],
]);

it('reads any other value as the constant it is', function (mixed $value, ConstValue $expected): void {
    expect(LiveRuleValue::of($value))->toEqual($expected);
})->with([
    'a rule string' => ['required', ConstValue::scalar('required')],
    'null' => [null, ConstValue::scalar(null)],
    'a case' => [WidgetStatus::Draft, ConstValue::enumCase(WidgetStatus::class, 'Draft')],
    // Keys kept, as the fold of the array written out keeps them, for a constructor that reads them.
    'a keyed list' => [['a' => 'x', 'b' => 1], ConstValue::array([ConstValue::scalar('x'), ConstValue::scalar(1)], ['a', 'b'])],
    'a list' => [['x', 1], ConstValue::array([ConstValue::scalar('x'), ConstValue::scalar(1)])],
    // A class is all a `new` names for an object no fold reads by its arguments.
    'an application rule' => [new TenantMember, ConstValue::instance(TenantMember::class, [])],
    'a closure' => [static fn (): bool => true, ConstValue::instance(Closure::class, [])],
]);

it('reads a value no rule list can hold as unknown', function (): void {
    $stream = fopen('php://memory', 'r');

    expect(LiveRuleValue::of($stream)->kind)->toBe(ConstValue::KIND_UNKNOWN);
});

it('reads a framework rule object Laravel casts to a string as that string', function (Closure $live): void {
    // Laravel runs neither of these as a rule of its own: its parser casts each to a rule string, and that is what
    // the field is validated with.
    $rule = $live();

    expect((new ConstValueToRules)->fold(LiveRuleValue::of($rule)))->toEqual((new ConstValueToRules)->fold(laravelParsedRules($rule)));
})->with([
    'a minimum width' => [static fn () => new Dimensions(['min_width' => 10])],
    'bounds set one by one' => [static fn () => (new Dimensions)->maxHeight(50)->minWidth(10)->ratio(3 / 2)],
]);

it('reads a rule object that holds on another field as the string it casts to, as a rules array reads it', function (string $class, array $arguments): void {
    // Laravel reads each by the rule string it stands for, so the attribute's object, the same object written
    // with new in a rules array, and the rules Laravel's parser makes of it are one rule.
    $rule = new $class(...$arguments);
    $written = ConstValue::instance($class, array_map(
        static fn (mixed $value): ConstValue => is_array($value) ? ConstValue::array(array_map(ConstValue::scalar(...), $value)) : ConstValue::scalar($value),
        $arguments,
    ));

    $live = (new ConstValueToRules)->fold(LiveRuleValue::of($rule));

    expect($live)->not->toBe([])
        ->and($live)->toEqual((new ConstValueToRules)->fold(laravelParsedRules($rule)))
        ->and($live)->toEqual((new ConstValueToRules)->fold(ConstValue::array([$written])));
})->with([
    'accepted on a value' => [$rules.'AcceptedIf', ['kind', 'business']],
    'declined on any of several' => [$rules.'DeclinedIf', ['kind', ['business', 'charity']]],
    'missing on a value' => [$rules.'MissingIf', ['kind', 'business']],
    'present unless a value' => [$rules.'PresentUnless', ['kind', 'person']],
    'required with fields' => [$rules.'RequiredWith', [['vat', 'company']]],
    'required once accepted' => [$rules.'RequiredIfAccepted', ['newsletter']],
    'prohibiting a field' => [$rules.'Prohibits', ['alias']],
])->skip(! class_exists($rules.'AcceptedIf'), 'This Laravel builds these rules as strings, not objects.');

it('reads a conditional rule object settled where it was built as the rule Laravel casts it to', function (string $class, bool $condition): void {
    if (! class_exists($class)) {
        $this->markTestSkipped('The installed Laravel has no '.$class.'.');
    }

    // A literal condition is what the object's own cast reads, so the field is validated with the rule it
    // applies, or with none at all; Laravel's parser is the statement of which.
    $rule = new $class($condition);

    expect((new ConstValueToRules)->fold(LiveRuleValue::of($rule)))->toEqual((new ConstValueToRules)->fold(laravelParsedRules($rule)));
})->with(array_merge(...array_map(
    static fn (string $short): array => [
        $short.'(true)' => ['Illuminate\\Validation\\Rules\\'.$short, true],
        $short.'(false)' => ['Illuminate\\Validation\\Rules\\'.$short, false],
    ],
    ['RequiredIf', 'RequiredUnless', 'ProhibitedIf', 'ProhibitedUnless', 'ExcludeIf', 'ExcludeUnless'],
)));

it('reads a conditional rule object on a closure without running the closure', function (string $class, string $unknown): void {
    if (! class_exists($class)) {
        $this->markTestSkipped('The installed Laravel has no '.$class.'.');
    }

    // The closure answers for the request it is called in, which no build is; its answer at build time is no
    // statement about the endpoint. So the rule stands on a condition this build cannot read.
    $ran = false;
    $rule = new $class(static function () use (&$ran): bool {
        $ran = true;

        return true;
    });

    $read = (new ConstValueToRules)->fold(LiveRuleValue::of($rule));

    expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $read))->toBe([[$unknown, []]])
        ->and($ran)->toBeFalse();
})->with([
    'RequiredIf' => ['Illuminate\\Validation\\Rules\\RequiredIf', 'required_if'],
    'RequiredUnless' => ['Illuminate\\Validation\\Rules\\RequiredUnless', 'required_unless'],
    'ProhibitedIf' => ['Illuminate\\Validation\\Rules\\ProhibitedIf', 'prohibited_if'],
    'ProhibitedUnless' => ['Illuminate\\Validation\\Rules\\ProhibitedUnless', 'prohibited_unless'],
    'ExcludeIf' => ['Illuminate\\Validation\\Rules\\ExcludeIf', 'exclude_if'],
    'ExcludeUnless' => ['Illuminate\\Validation\\Rules\\ExcludeUnless', 'exclude_unless'],
]);

it('reads by its state each framework rule Laravel validates with the rules it holds, and no application rule', function (): void {
    // Laravel's own password, file, image file and email rules, however the class is written; an application's
    // subclass may build its rules any way it likes, and a dimensions rule is read by the string it casts to.
    expect(LiveRuleValue::readsState(Password::class))->toBeTrue()
        ->and(LiveRuleValue::readsState('\\'.File::class))->toBeTrue()
        ->and(LiveRuleValue::readsState(ImageFile::class))->toBeTrue()
        ->and(LiveRuleValue::readsState(Email::class))->toBeTrue()
        ->and(LiveRuleValue::readsState(RecordedUpload::class))->toBeFalse()
        ->and(LiveRuleValue::readsState(Dimensions::class))->toBeFalse();
});

it('reads a password as the rules Laravel validates it with first', function (Closure $live, bool $checks): void {
    $rule = $live();
    if (! $rule instanceof IteratorAggregate) {
        $this->markTestSkipped('The installed Laravel lists no rules for a password.');
    }

    // Laravel validates the value with the rules the password lists, then runs its own checks on it, none of
    // which a schema can state: the fold reads the first and names the password as a rule it left out.
    $folder = new ConstValueToRules;
    $read = $folder->fold(LiveRuleValue::of($rule));
    $listed = (new ConstValueToRules)->fold(ConstValue::array(array_map(ConstValue::scalar(...), iterator_to_array($rule, false))));

    expect($read)->toEqual($listed)
        ->and($folder->unread())->toBe($checks ? ['new '.Password::class] : [])
        ->and($folder->opaque())->toBeNull();
})->with([
    'a minimum' => [static fn () => Password::min(10), false],
    'a maximum too' => [static fn () => Password::min(8)->max(64), false],
    'rules of its own' => [static fn () => Password::min(8)->rules(['alpha']), false],
    'letters' => [static fn () => Password::min(8)->letters(), true],
    'mixed case' => [static fn () => Password::min(8)->mixedCase(), true],
    'numbers' => [static fn () => Password::min(8)->numbers(), true],
    'symbols' => [static fn () => Password::min(8)->symbols(), true],
    'a breach lookup' => [static fn () => Password::min(8)->uncompromised(), true],
]);
