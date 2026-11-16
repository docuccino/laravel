<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Integrations\Validation\CastRuleObject;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Docuccino\Laravel\Integrations\Validation\RuleFactories;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\LegacyStringRule;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\TenantMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationRuleParser;
use Workbench\App\Enums\FormChannel;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

/**
 * Covers the inline-validation folding crux: turning a statically-recovered {@see ConstValue} (a
 * field's rules from `$request->validate([...])`) into rules, including `Rule::*` descriptors that
 * are folded at the AST level before PHPStan would collapse them to a bare object, and `new` rule
 * objects documented by their class's `#[RuleSchema]`.
 */
it('folds a pipe-string value into rules', function (): void {
    $rules = (new ConstValueToRules)->fold(ConstValue::scalar('required|string|max:100'));

    expect(array_map(fn ($r) => [$r->name, $r->parameters], $rules))->toBe([
        ['required', []],
        ['string', []],
        ['max', ['100']],
    ]);
});

it('folds an array of scalar tokens and Rule::enum / Rule::in descriptors', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->name)->toBe('required')
        ->and($rules[1]->name)->toBe('enum')
        ->and($rules[1]->parameters)->toBe(['draft', 'published', 'archived'])
        ->and($rules[1]->note)->toBe(WidgetStatus::class);
});

it('folds Rule::in with an array argument into an in rule', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
        ConstValue::array([ConstValue::scalar('a'), ConstValue::scalar('b')]),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe('in')
        ->and($rules[0]->parameters)->toBe(['a', 'b']);
});

it('folds a choice descriptor whose values do not fold to nothing at all', function (string $method, array $args): void {
    // `Rule::in(MediaCollections::validNames())` / `Rule::enum($runtimeClass)`: the values are only known
    // at runtime. An empty `in`/`enum` rule would be worse than none — it wins the per-field merge over
    // whatever else documents the field, then contributes no keyword — so it folds to nothing and the
    // field is reported unrecoverable instead.
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$method, $args);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
})->with([
    'in with an unfoldable argument' => ['in', []],
    'in with a non-scalar argument' => ['in', [ConstValue::array([])]],
    'enum naming no class' => ['enum', []],
    'enum naming a class that is not an enum' => ['enum', [ConstValue::scalar(ConstValueToRules::class)]],
]);

it('folds a Rule::enum narrowed to nothing to no rule', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::array([ConstValue::scalar('NoSuchCase')])]);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
});

it('keeps exists and unique, which legitimately carry no values', function (string $method): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$method, [ConstValue::scalar('users')]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe($method)
        ->and($rules[0]->parameters)->toBe([]);
})->with(['exists', 'unique']);

it('ignores a descriptor it does not recognise', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::mystery', [ConstValue::scalar('x')]);

    expect((new ConstValueToRules)->fold($value))->toBe([]);
});

it('narrows a Rule::enum case list with an ->only([...]) chain (validation §4 #10)', function (): void {
    // Rule::enum(WidgetStatus::class)->only([WidgetStatus::Draft, WidgetStatus::Published]) — the chain
    // args fold to the cases; narrowEnum keeps their backing values in declaration order.
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::array([ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::enumCase(WidgetStatus::class, 'Published')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->name)->toBe('enum')
        ->and($rules[0]->parameters)->toBe(['draft', 'published'])
        ->and($rules[0]->note)->toBe(WidgetStatus::class);
});

it('narrows a Rule::enum case list with an ->except([...]) chain', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('except', [ConstValue::array([ConstValue::enumCase(WidgetStatus::class, 'Archived')])]);

    $rules = (new ConstValueToRules)->fold($value);

    // Declaration order preserved; only the excepted case is dropped.
    expect($rules[0]->parameters)->toBe(['draft', 'published'])
        ->and($rules[0]->note)->toBe(WidgetStatus::class);
});

it('applies chained only() then except() left to right, spread-arg form included', function (): void {
    // only(Draft, Published, Archived) as separate args, then except([Published]).
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('only', [ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::enumCase(WidgetStatus::class, 'Published'), ConstValue::enumCase(WidgetStatus::class, 'Archived')])
        ->withChainedCall('except', [ConstValue::array([ConstValue::enumCase(WidgetStatus::class, 'Published')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->parameters)->toBe(['draft', 'archived']);
});

it('folds a rule object whose class carries a RuleSchema, ignoring its constructor args', function (): void {
    $folder = new ConstValueToRules;

    // `new BankReference('GB')` — the argument is not part of the documented contract.
    $rules = $folder->fold(ConstValue::instance(BankReference::class, [ConstValue::scalar('GB')]));

    expect(array_map(fn ($r) => $r->name, $rules))
        ->toBe(['string', 'regex', 'min', 'max', 'format', 'description', 'example'])
        ->and($folder->dependencyFiles())->toBe([(new ReflectionClass(BankReference::class))->getFileName()]);
});

it('folds a rule object beside string rules in an array-form rule list', function (): void {
    $value = ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::scalar('nullable'),
        ConstValue::instance(BankReference::class, []),
    ]);

    $rules = (new ConstValueToRules)->fold($value);

    expect(array_map(fn ($r) => $r->name, $rules))
        ->toBe(['required', 'nullable', 'string', 'regex', 'min', 'max', 'format', 'description', 'example']);
});

it('folds an unannotated rule object to nothing, leaving the field unrecoverable', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::instance(OpaqueCheck::class, [])))->toBe([])
        // Its file is still a dependency: adding the attribute has to re-document the field.
        ->and($folder->dependencyFiles())->toBe([(new ReflectionClass(OpaqueCheck::class))->getFileName()]);
});

it('folds a rule object of an unknown class to nothing with no dependency', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::instance('App\\Rules\\Missing', [])))->toBe([])
        ->and($folder->dependencyFiles())->toBe([]);
});

it('ignores an unknown chained method and keeps the full case list', function (): void {
    $value = ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall('somethingElse', [ConstValue::array([ConstValue::enumCase(WidgetStatus::class, 'Draft')])]);

    $rules = (new ConstValueToRules)->fold($value);

    expect($rules[0]->parameters)->toBe(['draft', 'published', 'archived']);
});

it('publishes no choice list at all where a rule spreads values in, and says it widened', function (): void {
    // The half that is written is the hazard: `enum: ["any"]` makes a generated client REJECT every
    // status the endpoint accepts. There is no truthful partial answer, so the constraint goes and the
    // rules written beside it stand.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
            ConstValue::scalar('any'),
            ConstValue::spread('unplaceable factory arg'),
        ]),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['required'])
        ->and($folder->widened())->toBeTrue();
});

it('keeps every enum case where a narrowing chain spreads its selection in', function (): void {
    // A half-read `->only(...)` drops cases the endpoint accepts, which is narrower than the truth. The
    // full case list is the widening.
    $folder = new ConstValueToRules;
    $descriptor = ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::scalar(WidgetStatus::class)],
    )->withChainedCall('only', [ConstValue::scalar('Active'), ConstValue::spread('unplaceable chained-call arg')]);

    $rules = $folder->fold($descriptor);

    expect(array_map(static fn ($rule): array => $rule->parameters, $rules))
        ->toBe([array_map(static fn (WidgetStatus $case): string => (string) $case->value, WidgetStatus::cases())])
        ->and($folder->widened())->toBeTrue();
});

it('publishes no enum where the class itself is spread in', function (): void {
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::spread('unplaceable factory arg')],
    )))->toBe([])
        ->and($folder->widened())->toBeTrue();
});

it('gives up a rules list that spread rules in, rather than keeping the ones written beside them', function (): void {
    // `['string', ...$this->extra()]` is only `string` while the spread holds no `nullable`, `sometimes` or
    // `exclude`. Holding one, the endpoint takes null or no value at all, and a document publishing the
    // `string` beside it refuses a request the API accepts. Nothing written beside the spread is true on
    // its own, so the field is given up and named.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('string'),
        ConstValue::spread('spread array item'),
    ]));

    expect($rules)->toBe([])
        ->and($folder->opaque())->toBe('a spread')
        ->and($folder->widened())->toBeFalse();
});

it('reports nothing lost for a fold that read every rule', function (): void {
    $folder = new ConstValueToRules;
    $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [ConstValue::scalar('a'), ConstValue::scalar('b')]),
        ConstValue::instance(BankReference::class, []),
        ConstValue::instance(TenantMember::class, []),
    ]));

    expect($folder->widened())->toBeFalse()
        ->and($folder->unread())->toBe([])
        ->and($folder->opaque())->toBeNull();
});

it('leaves out a rule it cannot read that can only refuse values, keeps the rest and names it', function (ConstValue $unread, string $named): void {
    // Each of these is run by Laravel as a check on the value, or is one of its own rules, and a check can
    // only refuse: the field without it accepts everything the endpoint does and more. That is wider than
    // the endpoint and still true, so `string` stands and the rule is named for the author.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([ConstValue::scalar('string'), $unread]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['string'])
        ->and($folder->unread())->toBe([$named])
        ->and($folder->opaque())->toBeNull();
})->with([
    'an unannotated rule object' => [ConstValue::instance(OpaqueCheck::class, []), 'new '.OpaqueCheck::class],
    'a rule object chained after new' => [ConstValue::instance(OpaqueCheck::class, [])->withChainedCall('strict', []), 'new '.OpaqueCheck::class],
    'a rule object of a class this build cannot load' => [ConstValue::instance('App\\Rules\\Missing', []), 'new App\\Rules\\Missing'],
    'a closure' => [ConstValue::instance('Closure', []), 'a closure'],
    // What the application registered at boot, which no file the build keys on states.
    'Password::defaults()' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::defaults', []), 'Password::defaults(…)'],
    'File::default()' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::default', []), 'File::default(…)'],
    'File::types() with values nothing can read' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::types', [ConstValue::unknown('variable')]), 'File::types(…)'],
    'Rule::contains() with values nothing can read' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::contains', [ConstValue::unknown('variable')]), 'Rule::contains(…)'],
    'Rule::string() with a bound nothing can read' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::string', [])->withChainedCall('max', [ConstValue::unknown('call')]), 'Rule::string(…)'],
    'new Password() with a minimum nothing can read' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Password', [ConstValue::unknown('call')]), 'new Illuminate\\Validation\\Rules\\Password'],
    'new Contains() with values nothing can read' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Contains', [ConstValue::array([ConstValue::scalar('root'), ConstValue::spread('spread array item')])]), 'new Illuminate\\Validation\\Rules\\Contains'],
]);

it('gives up a field one of whose rules has content it cannot read, and names that rule', function (ConstValue $opaque, string $named): void {
    // Each of these can turn into `nullable`, `sometimes` or `exclude` at runtime — a conditional or
    // nested rule expands to other rules, an app's helper or a variable returns any of them, and Laravel
    // casts a rule object implementing no contract to the string it returns. Any of those makes the
    // `string` beside it untrue on its own, so no rule of the field is kept.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([ConstValue::scalar('string'), $opaque, ConstValue::instance(OpaqueCheck::class, [])]));

    expect($rules)->toBe([])
        ->and($folder->opaque())->toBe($named);
})->with([
    'Rule::when()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::when', [ConstValue::unknown('call'), ConstValue::array([ConstValue::scalar('nullable')])]), 'Rule::when(…)'],
    'Rule::unless()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::unless', [ConstValue::scalar(true), ConstValue::scalar('sometimes')]), 'Rule::unless(…)'],
    'Rule::forEach()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::forEach', [ConstValue::unknown('closure')]), 'Rule::forEach(…)'],
    'a Rule macro' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::optionalSlug', []), 'Rule::optionalSlug(…)'],
    'an application helper' => [ConstValue::descriptor('App\\Support\\Rules::nullableName', []), 'Rules::nullableName(…)'],
    'new ConditionalRules()' => [ConstValue::instance('Illuminate\\Validation\\ConditionalRules', [ConstValue::scalar(true), ConstValue::scalar('nullable')]), 'new Illuminate\\Validation\\ConditionalRules'],
    'new NestedRules()' => [ConstValue::instance('Illuminate\\Validation\\NestedRules', [ConstValue::unknown('closure')]), 'new Illuminate\\Validation\\NestedRules'],
    'a rule object Laravel casts to a string' => [ConstValue::instance(LegacyStringRule::class, []), 'new '.LegacyStringRule::class],
    'a value from a call or a variable' => [ConstValue::unknown('non-constant array item'), 'a value this build cannot read'],
    'a nested array' => [ConstValue::array([ConstValue::scalar('nullable')]), 'a nested array'],
]);

it('reads a rule object Laravel validates by its state as the rules it runs, so the field takes all the object takes', function (ConstValue $written, Closure $live, Closure $samples, bool $checks): void {
    // Laravel validates a password, file or email object with a rule list its state builds, and a password then
    // runs checks of its own. The fold, rebuilt as rule strings and handed to Laravel, must take every value the
    // object takes; with no check of its own beside it, it must take nothing more either.
    $folder = new ConstValueToRules;
    $rules = $folder->fold($written);
    $strings = array_map(static fn ($rule): string => $rule->parameters === [] ? $rule->name : $rule->name.':'.implode(',', $rule->parameters), $rules);

    expect($strings)->not->toBeEmpty();
    foreach ($samples() as $label => $value) {
        $byObject = Validator::make(['f' => $value], ['f' => [$live()]])->passes();
        $byRules = Validator::make(['f' => $value], ['f' => $strings])->passes();

        expect(! $byObject || $byRules)->toBeTrue((string) $label);
        if (! $checks) {
            expect($byRules)->toBe($byObject, (string) $label);
        }
    }

    // The same object PHP built reads alike, whichever path it reached the fold by.
    $fromLive = new ConstValueToRules;
    expect($fromLive->fold(LiveRuleValue::of($live())))->toEqual($rules)
        ->and($folder->unread() !== [])->toBe($checks)
        ->and($fromLive->unread() !== [])->toBe($checks);
})->with([
    'new Password()' => [
        ConstValue::instance('Illuminate\\Validation\\Rules\\Password', [ConstValue::scalar(8)]),
        static fn () => new Password(8),
        static fn (): array => ['seven' => 'abcdefg', 'eight' => 'abcdefgh', 'a number' => 12345678, 'nothing' => null],
        false,
    ],
    'Password::min() bounded both ways, with rules of its own' => [
        ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::min', [ConstValue::scalar(8)])->withChainedCall('max', [ConstValue::scalar(12)])->withChainedCall('rules', [ConstValue::array([ConstValue::scalar('alpha')])]),
        static fn () => Password::min(8)->max(12)->rules(['alpha']),
        static fn (): array => ['eight letters' => 'abcdefgh', 'thirteen letters' => 'abcdefghijklm', 'with a digit' => 'abcdefg1'],
        false,
    ],
    'Password::min() needing letters in both cases' => [
        ConstValue::descriptor('\\Illuminate\\Validation\\Rules\\Password::min', [ConstValue::scalar(8)])->withChainedCall('letters', [])->withChainedCall('mixedCase', []),
        static fn () => Password::min(8)->letters()->mixedCase(),
        static fn (): array => ['lower case' => 'abcdefgh', 'mixed case' => 'abcdEFGH', 'short' => 'aB'],
        true,
    ],
    'Rule::email() compliant with the RFC' => [
        ConstValue::descriptor('Illuminate\\Validation\\Rule::email', [])->withChainedCall('rfcCompliant', []),
        static fn () => Rule::email()->rfcCompliant(),
        static fn (): array => ['an address' => 'ada@example.com', 'no address' => 'ada', 'a number' => 5],
        false,
    ],
    'new Email()' => [
        ConstValue::instance('Illuminate\\Validation\\Rules\\Email', []),
        static fn () => new Email,
        static fn (): array => ['an address' => 'ada@example.com', 'no address' => 'ada'],
        false,
    ],
    'File::types() under a size' => [
        ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::types', [ConstValue::array([ConstValue::scalar('pdf')])])->withChainedCall('max', [ConstValue::scalar('1mb')]),
        static fn () => File::types(['pdf'])->max('1mb'),
        static fn (): array => [
            'a small pdf' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            'a large pdf' => UploadedFile::fake()->create('b.pdf', 2000, 'application/pdf'),
            'a text file' => UploadedFile::fake()->create('c.txt', 10, 'text/plain'),
            'a string' => 'a.pdf',
        ],
        false,
    ],
    'File::image() within dimensions' => [
        ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::image', [])->withChainedCall('dimensions', [ConstValue::descriptor('Illuminate\\Validation\\Rule::dimensions', [])->withChainedCall('maxWidth', [ConstValue::scalar(100)])]),
        static fn () => File::image()->dimensions(Rule::dimensions()->maxWidth(100)),
        static fn (): array => [
            'a narrow image' => UploadedFile::fake()->image('a.png', 50, 50),
            'a wide image' => UploadedFile::fake()->image('b.png', 200, 50),
            'a text file' => UploadedFile::fake()->create('c.txt', 10, 'text/plain'),
        ],
        false,
    ],
    'Rule::imageFile() written with new' => [
        ConstValue::instance('Illuminate\\Validation\\Rules\\ImageFile', [])->withChainedCall('max', [ConstValue::scalar(5)]),
        static fn () => (new ImageFile)->max(5),
        static fn (): array => ['a small image' => UploadedFile::fake()->image('a.png', 10, 10), 'a large file' => UploadedFile::fake()->image('b.png', 10, 10)->size(50)],
        false,
    ],
]);

it('leaves a rule object Laravel validates by its state out where the build would run the application\'s code', function (ConstValue $written, string $named): void {
    // Each answers with what the application registered at boot, or a callable, which nothing here runs.
    Password::defaults(static fn () => Password::min(12));

    try {
        $folder = new ConstValueToRules;
        expect($folder->fold(ConstValue::array([ConstValue::scalar('string'), $written])))->toHaveCount(1)
            ->and($folder->unread())->toBe([$named]);
    } finally {
        Password::$defaultCallback = null;
    }
})->with([
    'Password::defaults()' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::defaults', []), 'Password::defaults(…)'],
    'Password::required()' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::required', []), 'Password::required(…)'],
    'Email::default()' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Email::default', []), 'Email::default(…)'],
    'a password checked by a callable' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::min', [ConstValue::scalar(8)])->withChainedCall('when', [ConstValue::scalar(true), ConstValue::scalar('trim')]), 'Password::min(…)'],
]);

it('reads a framework rule object narrowed by a chained call as the factory chain that builds it', function (): void {
    // `(new Enum(X::class))->only([...])` is `Rule::enum(X::class)->only([...])`, so it publishes the cases
    // the endpoint takes, not every case of the enum.
    $chained = static fn (string $method): ConstValue => ConstValue::instance('Illuminate\\Validation\\Rules\\Enum', [ConstValue::scalar(WidgetStatus::class)])
        ->withChainedCall($method, [ConstValue::array([ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::enumCase(WidgetStatus::class, 'Published')])]);

    $only = (new ConstValueToRules)->fold($chained('only'));
    $except = (new ConstValueToRules)->fold($chained('except'));

    expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $only))->toBe([['enum', ['draft', 'published']]])
        ->and(array_map(static fn ($r): array => [$r->name, $r->parameters], $except))->toBe([['enum', ['archived']]]);
});

it('widens an enum rule whose class it cannot read, rather than dropping it unnamed', function (): void {
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('string'),
        ConstValue::instance('Illuminate\\Validation\\Rules\\Enum', [ConstValue::unknown('non-constant constructor arg')]),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['string'])
        ->and($folder->widened())->toBeTrue();
});

it('publishes no choice list where a value comes from a call, and says it widened', function (): void {
    // A spread is only the loudest way a value goes unread. `Rule::in('any', $this->fallback())` folds
    // arg 1 to an `unknown`, and a reader that watched only for spreads published `in: ["any"]` — one
    // legal value out of two, which is the same lie in a quieter shape.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('required'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
            ConstValue::scalar('any'),
            ConstValue::unknown('non-constant factory arg'),
        ]),
    ]));

    expect(array_map(static fn ($rule): string => $rule->name, $rules))->toBe(['required'])
        ->and($folder->widened())->toBeTrue();
});

it('keeps every enum case where a narrowing chain names one from a call', function (): void {
    // The narrowing direction, and the dangerous one: a half-read `->only([...])` drops cases the
    // endpoint accepts. The full case list is the widening.
    $folder = new ConstValueToRules;
    $descriptor = ConstValue::descriptor(
        'Illuminate\\Validation\\Rule::enum',
        [ConstValue::scalar(WidgetStatus::class)],
    )->withChainedCall('only', [ConstValue::array([
        ConstValue::enumCase(WidgetStatus::class, 'Draft'),
        ConstValue::unknown('non-constant array item'),
    ])]);

    $rules = $folder->fold($descriptor);

    expect($rules[0]->parameters)->toBe(['draft', 'published', 'archived'])
        ->and($folder->widened())->toBeTrue();
});

it('widens for any entry of a value list that names no value', function (ConstValue $entry): void {
    // Every shape the fold can put in a value list, one dataset entry each: whatever the entry is, if it
    // does not name a value then the list is short, and a short list is the one thing never published.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [
        ConstValue::scalar('written'),
        $entry,
    ]));

    expect($rules)->toBe([])
        ->and($folder->widened())->toBeTrue();
})->with([
    'a spread' => fn (): ConstValue => ConstValue::spread('unplaceable factory arg'),
    'an argument the fold gave up on' => fn (): ConstValue => ConstValue::unknown('non-constant factory arg'),
    'a nested array' => fn (): ConstValue => ConstValue::array([ConstValue::scalar('a')]),
    'another descriptor' => fn (): ConstValue => ConstValue::descriptor('App\\Choices::all', []),
    'a rule object' => fn (): ConstValue => ConstValue::instance(BankReference::class, []),
    'a bare null' => fn (): ConstValue => ConstValue::scalar(null),
]);

it('publishes an argument list that states nothing as no constraint, and widens nothing', function (array $args): void {
    // `Rule::in()` and `Rule::in([])` state no values at all, so there is nothing to have missed. They
    // stay what they were: no rule, and no report of a loss.
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::descriptor('Illuminate\\Validation\\Rule::in', $args)))->toBe([])
        ->and($folder->widened())->toBeFalse();
})->with([
    'no arguments at all' => [[]],
    'one empty array argument' => [[ConstValue::array([])]],
]);

it('folds every exclude descriptor and rule object to the exclude rule it stands for', function (ConstValue $value, array $expected): void {
    // Laravel stops running a field's rules at the first exclude rule that fires, so dropping one of these
    // would leave a `required` written after it reading as unconditional. A literal bool is decided now,
    // exactly as `__toString()` decides it; a callback is not, so the rule stays with its condition unknown.
    $rules = (new ConstValueToRules)->fold($value);

    expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $rules))->toBe($expected);
})->with([
    'Rule::excludeIf(callback)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', [ConstValue::unknown('closure')]), [['exclude_if', []]]],
    'Rule::excludeUnless(callback)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeUnless', [ConstValue::unknown('closure')]), [['exclude_unless', []]]],
    'Rule::excludeIf(true)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', [ConstValue::scalar(true)]), [['exclude', []]]],
    'Rule::excludeIf(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', [ConstValue::scalar(false)]), []],
    'Rule::excludeUnless(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeUnless', [ConstValue::scalar(false)]), [['exclude', []]]],
    'Rule::excludeUnless(true)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeUnless', [ConstValue::scalar(true)]), []],
    'Rule::excludeIf with its condition spread in' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', [ConstValue::spread('unplaceable factory arg')]), [['exclude_if', []]]],
    'Rule::excludeIf with no argument' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', []), [['exclude_if', []]]],
    'new ExcludeIf(callback)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ExcludeIf', [ConstValue::unknown('closure')]), [['exclude_if', []]]],
    'new ExcludeUnless(callback)' => [ConstValue::instance('\\Illuminate\\Validation\\Rules\\ExcludeUnless', [ConstValue::unknown('closure')]), [['exclude_unless', []]]],
    'new ExcludeIf(true)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ExcludeIf', [ConstValue::scalar(true)]), [['exclude', []]]],
    'new ExcludeUnless(true)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ExcludeUnless', [ConstValue::scalar(true)]), []],
    'Rule::exclude()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::exclude', []), [['exclude', []]]],
    'Rule::excludeWith(field)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeWith', [ConstValue::scalar('card')]), [['exclude_with', ['card']]]],
    'Rule::excludeWithout(fields)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeWithout', [ConstValue::scalar('card'), ConstValue::scalar('iban')]), [['exclude_without', ['card', 'iban']]]],
    'Rule::excludeWithout([fields])' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeWithout', [ConstValue::array([ConstValue::scalar('card'), ConstValue::scalar('iban')])]), [['exclude_without', ['card', 'iban']]]],
    'Rule::excludeWith with a field nothing can read' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeWith', [ConstValue::unknown('variable')]), [['exclude_with', []]]],
    'new ExcludeWith(field)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ExcludeWith', [ConstValue::scalar('card')]), [['exclude_with', ['card']]]],
    'new ExcludeWithout([fields])' => [ConstValue::instance('\\Illuminate\\Validation\\Rules\\ExcludeWithout', [ConstValue::array([ConstValue::scalar('card')])]), [['exclude_without', ['card']]]],
    'beside the rules written after it' => [ConstValue::array([
        ConstValue::descriptor('Illuminate\\Validation\\Rule::excludeIf', [ConstValue::unknown('closure')]),
        ConstValue::scalar('required'),
        ConstValue::scalar('string'),
    ]), [['exclude_if', []], ['required', []], ['string', []]]],
]);

it('folds every required and prohibited descriptor and rule object to the rule its condition settles', function (ConstValue $value, array $expected): void {
    // A literal bool is decided now, as `__toString()` decides it: the rule, or none, so a false condition
    // leaves the field optional or sendable. A callback or a value only the request decides is not, so the
    // rule stays with its condition unknown, which requires and refuses nothing.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([ConstValue::scalar('string'), $value]));

    expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $rules))->toBe([['string', []], ...$expected])
        ->and($folder->unread())->toBe([])
        ->and($folder->opaque())->toBeNull();
})->with([
    'Rule::requiredIf(true)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredIf', [ConstValue::scalar(true)]), [['required', []]]],
    'Rule::requiredIf(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredIf', [ConstValue::scalar(false)]), []],
    'Rule::requiredIf(callback)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredIf', [ConstValue::unknown('closure')]), [['required_if', []]]],
    'Rule::requiredUnless(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredUnless', [ConstValue::scalar(false)]), [['required', []]]],
    'Rule::requiredUnless(true)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredUnless', [ConstValue::scalar(true)]), []],
    'Rule::prohibitedIf(true)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::prohibitedIf', [ConstValue::scalar(true)]), [['prohibited', []]]],
    'Rule::prohibitedIf(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::prohibitedIf', [ConstValue::scalar(false)]), []],
    'Rule::prohibitedIf(callback)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::prohibitedIf', [ConstValue::unknown('closure')]), [['prohibited_if', []]]],
    'Rule::prohibitedUnless(false)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::prohibitedUnless', [ConstValue::scalar(false)]), [['prohibited', []]]],
    'Rule::prohibitedUnless(callback)' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::prohibitedUnless', [ConstValue::unknown('closure')]), [['prohibited_unless', []]]],
    'Rule::requiredIf with no argument' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::requiredIf', []), [['required_if', []]]],
    'new RequiredIf(true)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\RequiredIf', [ConstValue::scalar(true)]), [['required', []]]],
    'new RequiredIf(false)' => [ConstValue::instance('\\Illuminate\\Validation\\Rules\\RequiredIf', [ConstValue::scalar(false)]), []],
    'new RequiredIf(a value the request decides)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\RequiredIf', [ConstValue::unknown('call')]), [['required_if', []]]],
    'new RequiredUnless(callback)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\RequiredUnless', [ConstValue::unknown('closure')]), [['required_unless', []]]],
    'new ProhibitedIf(true)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ProhibitedIf', [ConstValue::scalar(true)]), [['prohibited', []]]],
    'new ProhibitedIf(false)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ProhibitedIf', [ConstValue::scalar(false)]), []],
    'new ProhibitedUnless(true)' => [ConstValue::instance('Illuminate\\Validation\\Rules\\ProhibitedUnless', [ConstValue::scalar(true)]), []],
]);

it('folds every framework factory and rule object that applies a rule on a condition', function (): void {
    // The rows above list them by hand; this reads the framework's own: every exclude rule, and every rule
    // object holding a `condition`, with the factories that build them. One Laravel adds would otherwise fold to
    // nothing, as any unrecognised descriptor or unannotated object does.
    $conditional = static fn (string $class): bool => str_starts_with($class, 'Illuminate\\Validation\\Rules\\') && class_exists($class)
        && (str_starts_with(substr($class, (int) strrpos($class, '\\') + 1), 'Exclude') || property_exists($class, 'condition'));
    $factories = array_values(array_filter(
        array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(Rule::class))->getMethods(ReflectionMethod::IS_STATIC)),
        static fn (string $name): bool => str_starts_with($name, 'exclude') || $conditional((string) RuleFactories::builtClass($name)),
    ));

    expect($factories)->not->toBeEmpty();
    foreach ($factories as $factory) {
        expect((new ConstValueToRules)->fold(ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$factory, [ConstValue::unknown('closure')])))->toHaveCount(1, $factory);
    }

    $classes = array_values(array_filter(
        array_map(static fn (string $file): string => 'Illuminate\\Validation\\Rules\\'.basename($file, '.php'), glob(dirname((string) (new ReflectionClass(Rule::class))->getFileName()).'/Rules/*.php') ?: []),
        $conditional,
    ));

    // ExcludeIf, ExcludeUnless and the four required and prohibited forms, on every Laravel this supports.
    expect(count($classes))->toBeGreaterThanOrEqual(6);
    foreach ($classes as $class) {
        expect((new ConstValueToRules)->fold(ConstValue::instance($class, [ConstValue::unknown('closure')])))->toHaveCount(1, $class);
    }
});

it('folds every object a Rule static builds, written either way, to the rules Laravel reads it as', function (): void {
    // Every static of the installed `Rule` that builds one of the framework's rule objects is called with
    // literal arguments named for its parameters, and the object built as `new` with the same arguments.
    // Laravel validates with one that casts to a string as the rules its own parser reads off it, so each
    // spelling owes exactly those. `Exists` and `Unique` are read by name alone: the table and column decide
    // nothing the document states. The rest are checks run on the value, read alike however written.
    $samples = [
        'anotherField' => 'kind', 'otherField' => 'kind', 'field' => 'kind', 'value' => 'business',
        'fields' => ['vat', 'company'], 'keys' => ['name', 'email'], 'values' => ['a', 'b'], 'table' => 'users',
        'type' => WidgetStatus::class, 'pattern' => '/^[a-z]+$/', 'rules' => ['string'], 'ability' => 'update',
    ];
    $conditional = ['excludeIf', 'excludeUnless', 'requiredIf', 'requiredUnless', 'prohibitedIf', 'prohibitedUnless'];
    $literal = static fn (mixed $value): ConstValue => is_array($value)
        ? ConstValue::array(array_map(static fn (string $item): ConstValue => ConstValue::scalar($item), $value))
        : ConstValue::scalar(is_scalar($value) ? $value : null);
    $read = static function (array $rules): array {
        return array_map(static fn ($rule): array => [ValidationRuleParser::parse($rule->name)[0], $rule->parameters], $rules);
    };
    $laravel = static function (object $rule): array {
        $pieces = (new ValidationRuleParser([]))->explode(['field' => [$rule]])->rules['field'];

        // An empty parameter list (`dimensions:`) parses as one null, which states no parameter.
        return array_map(static function ($piece): array {
            [$name, $parameters] = ValidationRuleParser::parse((string) $piece);

            return [$name, $parameters === [null] ? [] : $parameters];
        }, $pieces);
    };

    $statics = array_filter(
        (new ReflectionClass(Rule::class))->getMethods(ReflectionMethod::IS_STATIC),
        static fn (ReflectionMethod $method): bool => ! in_array($method->getName(), $conditional, true)
            && str_starts_with((string) RuleFactories::builtClass($method->getName()), 'Illuminate\\Validation\\Rules\\'),
    );

    $cast = 0;
    foreach ($statics as $static) {
        $name = $static->getName();
        $values = [];
        foreach ($static->getParameters() as $parameter) {
            if (! $parameter->isOptional()) {
                expect(array_key_exists($parameter->getName(), $samples))->toBeTrue("Rule::{$name}() takes \${$parameter->getName()}, which needs a sample.");
                $values[] = $samples[$parameter->getName()];
            }
        }

        $object = Rule::{$name}(...$values);
        $class = $object::class;
        $folded = array_map($literal, $values);
        $asFactory = (new ConstValueToRules)->fold(ConstValue::array([ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$name, $folded)]));
        $asObject = (new ConstValueToRules)->fold(ConstValue::array([ConstValue::instance($class, $folded)]));

        if (! CastRuleObject::casts($class)) {
            expect($asObject)->toEqual($asFactory, $name);

            continue;
        }

        $cast++;
        if (in_array($class, ['Illuminate\\Validation\\Rules\\Exists', 'Illuminate\\Validation\\Rules\\Unique'], true)) {
            expect($read($asFactory))->toBe([[ValidationRuleParser::parse(Fqcn::short($class))[0], []]], $name)
                ->and($asObject)->toEqual($asFactory, $name);

            continue;
        }

        expect($read($asFactory))->toBe($laravel($object), "Rule::{$name}()")
            ->and($read($asObject))->toBe($laravel(new $class(...$values)), "new {$class}");
    }

    // In, NotIn, ArrayRule, Exists, Unique, Contains, DoesntContain, Date, Dimensions, Numeric and StringRule,
    // on every Laravel this supports.
    expect($cast)->toBeGreaterThanOrEqual(11);
});

it('folds new Enum beside the rules written with it', function (): void {
    $rules = (new ConstValueToRules)->fold(ConstValue::array([
        ConstValue::scalar('distinct'),
        ConstValue::instance('Illuminate\\Validation\\Rules\\Enum', [ConstValue::scalar(WidgetStatus::class)]),
    ]));

    expect(array_map(static fn ($r): array => [$r->name, $r->parameters, $r->note], $rules))->toBe([
        ['distinct', [], null],
        ['enum', ['draft', 'published', 'archived'], WidgetStatus::class],
    ]);
});

it('reads a Rule static that returns a rule string as the string it returns', function (ConstValue $static, array $expected): void {
    // `Rule::nullable()` is `'nullable'` to Laravel, so it documents as `'nullable'` does: dropped as a check,
    // the `string` beside it would refuse the null the endpoint takes.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([ConstValue::scalar('string'), $static]));

    expect(array_map(static fn ($rule): array => [$rule->name, $rule->parameters], $rules))->toBe([['string', []], ...$expected])
        ->and($folder->unread())->toBe([])
        ->and($folder->opaque())->toBeNull();
})->with([
    'Rule::nullable()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::nullable', []), [['nullable', []]]],
    'a leading backslash, any case' => [ConstValue::descriptor('\\Illuminate\\Validation\\Rule::SOMETIMES', []), [['sometimes', []]]],
    'an argument written at the call' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::boolean', [ConstValue::scalar(true)]), [['boolean', ['strict']]]],
])->skip(! method_exists(Rule::class, 'nullable'), 'This Laravel declares no Rule static that returns a rule string.');

it('gives up a field whose rule-string static it cannot call as written', function (ConstValue $static, string $named): void {
    // Unread, a rule string could be `nullable` or `sometimes` itself, so nothing beside it holds alone.
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::array([ConstValue::scalar('string'), $static])))->toBe([])
        ->and($folder->opaque())->toBe($named);
})->with([
    'an argument nothing can read' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::boolean', [ConstValue::unknown('variable')]), 'Rule::boolean(…)'],
    'an argument spread in' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::boolean', [ConstValue::spread('unplaceable factory arg')]), 'Rule::boolean(…)'],
    'a call chained on it' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::nullable', [])->withChainedCall('strict', []), 'Rule::nullable(…)'],
    'an application class of the same name' => [ConstValue::descriptor('App\\Support\\Rule::nullable', []), 'Rule::nullable(…)'],
]);

it('gives up a field using a Rule static the installed Laravel does not declare', function (): void {
    // On a Laravel without `Rule::nullable()` the call goes through the macro table, which could hold any rule.
    $folder = new ConstValueToRules;

    expect($folder->fold(ConstValue::array([ConstValue::scalar('string'), ConstValue::descriptor('Illuminate\\Validation\\Rule::optionalSlug', [])])))->toBe([])
        ->and($folder->opaque())->toBe('Rule::optionalSlug(…)')
        ->and(RuleFactories::ruleString('Illuminate\\Validation\\Rule::optionalSlug', []))->toBeNull()
        ->and(RuleFactories::builtClass('optionalSlug'))->toBeNull();
});

it('reads Rule::notIn and new NotIn as the not_in rule Laravel casts them to', function (array $args, array $values): void {
    // Laravel validates a NotIn object as the `not_in:` string it casts to, so each spelling excludes the
    // values Laravel's own parser reads off that string.
    $real = array_map(static fn (ConstValue $arg): mixed => $arg->isArray() ? array_map(static fn (ConstValue $item): mixed => $item->scalar, $arg->items) : $arg->scalar, $args);
    [$name, $excluded] = ValidationRuleParser::parse((string) Rule::notIn(...$real));

    foreach ([ConstValue::descriptor('Illuminate\\Validation\\Rule::notIn', $args), ConstValue::instance('Illuminate\\Validation\\Rules\\NotIn', $args)] as $written) {
        $folder = new ConstValueToRules;
        $rules = $folder->fold(ConstValue::array([ConstValue::scalar('string'), $written]));

        expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $rules))->toBe([['string', []], ['not_in', $values]])
            ->and([$name, $rules[1]->parameters])->toBe(['NotIn', $excluded])
            ->and($folder->unread())->toBe([])
            ->and($folder->widened())->toBeFalse();
    }
})->with([
    'one array' => [[ConstValue::array([ConstValue::scalar('admin'), ConstValue::scalar('root')])], ['admin', 'root']],
    'separate arguments' => [[ConstValue::scalar('admin'), ConstValue::scalar('root')], ['admin', 'root']],
    'numbers and a bool' => [[ConstValue::array([ConstValue::scalar(0), ConstValue::scalar(true)])], ['0', '1']],
    // PHP spells `false` as an empty string, so the rule excludes `""` (and with it `false`), never `"0"`.
    'false and a float' => [[ConstValue::array([ConstValue::scalar(false), ConstValue::scalar(1.5)])], ['', '1.5']],
]);

it('publishes no exclusion where a value of Rule::notIn comes from a call, and says it widened', function (): void {
    // `Rule::notIn(Status::cases())`: the values are only known at runtime. Leaving the exclusion off is
    // wider than the endpoint and true, and the field is named for it.
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('string'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::notIn', [ConstValue::descriptor('App\\Enums\\Status::cases', [])]),
    ]));

    expect(array_map(static fn ($r): string => $r->name, $rules))->toBe(['string'])
        ->and($folder->widened())->toBeTrue()
        ->and($folder->unread())->toBe([]);
});

it('reads Rule::array and new ArrayRule as the array rule, keys or not', function (array $args, array $keys): void {
    // `Rule::array(['a'])` is `array:a`: the value is an array, with no keys but those. A key nothing here
    // can read only refuses keys, so the field is still an array and nothing is lost but that refusal.
    foreach ([ConstValue::descriptor('Illuminate\\Validation\\Rule::array', $args), ConstValue::instance('Illuminate\\Validation\\Rules\\ArrayRule', $args)] as $written) {
        $folder = new ConstValueToRules;
        $rules = $folder->fold(ConstValue::array([ConstValue::scalar('required'), $written]));

        expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $rules))->toBe([['required', []], ['array', $keys]])
            ->and($folder->unread())->toBe([])
            ->and($folder->widened())->toBeFalse();
    }
})->with([
    'no keys' => [[], []],
    'keys in one array' => [[ConstValue::array([ConstValue::scalar('name'), ConstValue::scalar('email')])], ['name', 'email']],
    'keys as separate arguments' => [[ConstValue::scalar('name'), ConstValue::scalar('email')], ['name', 'email']],
    'keys from a call' => [[ConstValue::descriptor('App\\Support\\Keys::all', [])], []],
]);

it('reads an enum case in a value list as the value Laravel casts it to, never its name', function (string $factory, string $rule, array $cases): void {
    // Laravel's In, NotIn and ArrayRule cast each case with `enum_value()` before comparing: a backed case is
    // its backing value and a pure one its name. So the rule Laravel's own parser reads off the object built
    // from the real cases is the rule the fold owes, for every spelling of it.
    [, $expected] = ValidationRuleParser::parse((string) Rule::{$factory}($cases));
    $folded = array_map(static fn (UnitEnum $case): ConstValue => ConstValue::enumCase($case::class, $case->name), $cases);

    $spellings = [
        'one array' => ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$factory, [ConstValue::array($folded)]),
        'separate arguments' => ConstValue::descriptor('Illuminate\\Validation\\Rule::'.$factory, $folded),
        'the object' => ConstValue::instance(RuleFactories::builtClass($factory) ?? '', [ConstValue::array($folded)]),
    ];

    foreach ($spellings as $spelling => $written) {
        $folder = new ConstValueToRules;
        $rules = $folder->fold(ConstValue::array([$written]));

        expect(array_map(static fn ($r): array => [$r->name, $r->parameters], $rules))->toBe([[$rule, $expected]], $spelling)
            ->and($folder->widened())->toBeFalse()
            // The values are written in the enum's file, so it keys the fragment.
            ->and($folder->dependencyFiles())->toBe([(new ReflectionEnum($cases[0]))->getFileName()]);
    }
})->with([
    'in, string-backed' => ['in', 'in', [WidgetStatus::Draft, WidgetStatus::Archived]],
    'in, int-backed' => ['in', 'in', [WidgetPriority::Low, WidgetPriority::High]],
    'in, pure' => ['in', 'in', [FormChannel::Email]],
    'notIn, string-backed' => ['notIn', 'not_in', [WidgetStatus::Archived]],
    'notIn, int-backed' => ['notIn', 'not_in', [WidgetPriority::Low]],
    'array, keys named by cases' => ['array', 'array', [WidgetStatus::Draft, WidgetStatus::Published]],
]);

it('names the enum a choice list is drawn from only where every entry is one of its cases', function (array $entries, ?string $enum): void {
    // A list of one enum's cases is a choice from that enum: its case names and prose describe it. A plain
    // value or another enum's case beside them makes it a list of values.
    $rules = (new ConstValueToRules)->fold(ConstValue::array([
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [ConstValue::array($entries)]),
    ]));

    expect($rules[0]->note)->toBe($enum);
})->with([
    'one enum' => [[ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::enumCase(WidgetStatus::class, 'Published')], WidgetStatus::class],
    'a case and a value' => [[ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::scalar('scheduled')], null],
    'two enums' => [[ConstValue::enumCase(WidgetStatus::class, 'Draft'), ConstValue::enumCase(FormChannel::class, 'Email')], null],
    'values only' => [[ConstValue::scalar('draft')], null],
]);

it('widens a choice list naming a case this build cannot reflect, rather than publishing a short list', function (): void {
    $folder = new ConstValueToRules;
    $rules = $folder->fold(ConstValue::array([
        ConstValue::scalar('string'),
        ConstValue::descriptor('Illuminate\\Validation\\Rule::in', [ConstValue::array([
            ConstValue::enumCase(WidgetStatus::class, 'Draft'),
            ConstValue::enumCase(WidgetStatus::class, 'Retired'),
        ])]),
    ]));

    expect(array_map(static fn ($r): string => $r->name, $rules))->toBe(['string'])
        ->and($folder->widened())->toBeTrue();
});

it('selects with only() and except() exactly the cases Laravel compares equal', function (string $method, array $selectors, array $values): void {
    // Laravel keeps or drops a case by comparing it strictly with the ones listed, so only a case of the
    // rule's own enum selects anything: a string, or another enum's case of the same name, never does.
    $rule = Rule::enum(WidgetStatus::class)->{$method}(array_map(static fn (ConstValue $s): mixed => $s->isCase() ? constant($s->class.'::'.$s->caseName()) : $s->scalar, $selectors));
    $accepted = array_values(array_filter(
        array_map(static fn (WidgetStatus $case): string => $case->value, WidgetStatus::cases()),
        static fn (string $value): bool => Validator::make(['v' => $value], ['v' => [$rule]])->passes(),
    ));

    $rules = (new ConstValueToRules)->fold(ConstValue::array([
        ConstValue::descriptor('Illuminate\\Validation\\Rule::enum', [ConstValue::scalar(WidgetStatus::class)])
            ->withChainedCall($method, [ConstValue::array($selectors)]),
    ]));

    expect($accepted)->toBe($values)
        ->and(array_map(static fn ($r): array => $r->parameters, $rules))->toBe($values === [] ? [] : [$values]);
})->with([
    'only, a case' => ['only', [ConstValue::enumCase(WidgetStatus::class, 'Draft')], ['draft']],
    'except, a case' => ['except', [ConstValue::enumCase(WidgetStatus::class, 'Draft')], ['published', 'archived']],
    'except, the case name as a string' => ['except', [ConstValue::scalar('Draft')], ['draft', 'published', 'archived']],
    'except, another enum\'s case' => ['except', [ConstValue::enumCase(FormChannel::class, 'Email')], ['draft', 'published', 'archived']],
    'only, the case name as a string' => ['only', [ConstValue::scalar('Draft')], []],
]);
