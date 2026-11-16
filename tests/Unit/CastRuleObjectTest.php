<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\Validation\CastRuleObject;
use Docuccino\Laravel\Tests\Fixtures\Rules\CallRecorder;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\RecordedModel;
use Docuccino\Laravel\Tests\Fixtures\Rules\RecordedUpload;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\StringRule;
use Workbench\App\Enums\WidgetStatus;

/*
 * A framework rule object written with literal values is built and read by Laravel's own parser. Building runs
 * Laravel's code on those values and nothing else, so every way a value could reach application code — a macro,
 * a callable, a model named as a table — is refused, and so is any object the fold could not rebuild exactly.
 */
/**
 * The rule strings `new $class(...$args)` with its chained calls is cast to, or null.
 *
 * @param  list<ConstValue>  $args
 * @param  list<array{method: string, args: list<ConstValue>}>  $chain
 * @return list<string>|null
 */
function castNew(string $class, array $args, array $chain): ?array
{
    $written = ConstValue::instance($class, $args);
    foreach ($chain as $call) {
        $written = $written->withChainedCall($call['method'], $call['args']);
    }

    return CastRuleObject::castStrings(CastRuleObject::build($written));
}

/**
 * The rule strings a static call with its chained calls is cast to, or null.
 *
 * @param  list<ConstValue>  $args
 * @param  list<array{method: string, args: list<ConstValue>}>  $chain
 * @return list<string>|null
 */
function castFactory(string $factory, array $args, array $chain): ?array
{
    $written = ConstValue::descriptor($factory, $args);
    foreach ($chain as $call) {
        $written = $written->withChainedCall($call['method'], $call['args']);
    }

    return CastRuleObject::castStrings(CastRuleObject::build($written));
}

beforeEach(function (): void {
    CallRecorder::$calls = 0;
});

afterEach(function (): void {
    Date::flushMacros();
    Password::$defaultCallback = null;
    File::$defaultCallback = null;
});

it('reads a rule object built from literal values as the rule strings Laravel splits it into', function (ConstValue $written, array $expected): void {
    $strings = CastRuleObject::castStrings(CastRuleObject::build($written));

    expect($strings)->toBe($expected);
})->with([
    'Rule::string() bounded' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::string', [])->withChainedCall('min', [ConstValue::scalar(2)])->withChainedCall('max', [ConstValue::scalar(40)]), ['string', 'min:2', 'max:40']],
    'new StringRule chained' => [ConstValue::instance('\\Illuminate\\Validation\\Rules\\StringRule', [])->withChainedCall('alphaDash', []), ['string', 'alpha_dash']],
    'Rule::date() in a format, after today' => [ConstValue::descriptor('\\Illuminate\\Validation\\Rule::date', [])->withChainedCall('format', [ConstValue::scalar('Y-m-d')])->withChainedCall('afterToday', []), ['date_format:Y-m-d', 'after:today']],
    'Rule::numeric() as a bounded integer' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::numeric', [])->withChainedCall('integer', [])->withChainedCall('min', [ConstValue::scalar(1)]), ['numeric', 'integer', 'min:1']],
    'Rule::contains() over a list' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::contains', [ConstValue::array([ConstValue::scalar('a'), ConstValue::scalar('b')])]), ['contains:"a","b"']],
    'new Dimensions with no constraints, then one set' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Dimensions', [])->withChainedCall('minWidth', [ConstValue::scalar(10)]), ['dimensions:min_width=10']],
    // Its keys are its constraints' names, so the array is built at the keys written.
    'new Dimensions with keyed constraints' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Dimensions', [ConstValue::array([ConstValue::scalar(10), ConstValue::scalar(20)], ['min_width', 'max_height'])]), ['dimensions:min_width=10,max_height=20']],
    'Rule::dimensions() with keyed constraints, then one more' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::dimensions', [ConstValue::array([ConstValue::scalar(10)], ['min_width'])])->withChainedCall('maxWidth', [ConstValue::scalar(90)]), ['dimensions:min_width=10,max_width=90']],
]);

it('builds a rule object Laravel validates by its state as PHP builds it from the same literals', function (ConstValue $written, Closure $expected): void {
    expect(CastRuleObject::build($written))->toEqual($expected());
})->with([
    'new Password()' => [ConstValue::instance('Illuminate\\Validation\\Rules\\Password', [ConstValue::scalar(8)]), static fn () => new Password(8)],
    'Password::min() with checks' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::min', [ConstValue::scalar(8)])->withChainedCall('letters', [])->withChainedCall('mixedCase', []), static fn () => Password::min(8)->letters()->mixedCase()],
    'File::types() under a size' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::types', [ConstValue::array([ConstValue::scalar('pdf')])])->withChainedCall('max', [ConstValue::scalar(1024)]), static fn () => File::types(['pdf'])->max(1024)],
    'File::image() handed dimensions' => [ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::image', [])->withChainedCall('dimensions', [ConstValue::descriptor('Illuminate\\Validation\\Rule::dimensions', [])->withChainedCall('maxWidth', [ConstValue::scalar(10)])]), static fn () => File::image()->dimensions(Rule::dimensions()->maxWidth(10))],
    'Rule::email()' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::email', [])->withChainedCall('rfcCompliant', [ConstValue::scalar(true)]), static fn () => Rule::email()->rfcCompliant(true)],
    'Rule::dimensions() with keyed constraints' => [ConstValue::descriptor('Illuminate\\Validation\\Rule::dimensions', [ConstValue::array([ConstValue::scalar(10)], ['min_width'])]), static fn () => Rule::dimensions(['min_width' => 10])],
]);

it('builds nothing from a value it cannot read or a call it would not make, and runs no application code', function (Closure $build): void {
    Date::macro('recorded', function (): Date {
        CallRecorder::record();

        return $this;
    });
    Password::defaults(static fn (): Password => CallRecorder::record(Password::min(12)));
    File::defaults(static fn (): File => CallRecorder::record(File::types(['pdf'])));

    expect($build())->toBeNull()
        ->and(CallRecorder::$calls)->toBe(0);
})->with([
    // The value would be guessed.
    'an argument nothing can read' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::contains', [ConstValue::unknown('variable')], [])],
    'an argument spread in' => [static fn (): ?array => castNew('Illuminate\\Validation\\Rules\\Contains', [ConstValue::spread('unplaceable')], [])],
    'an enum case' => [static fn (): ?array => castNew('Illuminate\\Validation\\Rules\\Contains', [ConstValue::enumCase(WidgetStatus::class, 'Draft')], [])],
    'a chained bound nothing can read' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'max', 'args' => [ConstValue::unknown('call')]]])],
    'too few arguments to a chained call' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'between', 'args' => [ConstValue::scalar(1)]]])],
    // An array whose keys the fold did not read, so a constructor reading them would be rebuilt wrong.
    'a constructor taking a keyed array' => [static fn (): ?array => castNew('Illuminate\\Validation\\Rules\\Dimensions', [ConstValue::array([ConstValue::scalar(10)])], [])],
    'a factory passing a keyed array on' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::dimensions', [ConstValue::array([ConstValue::scalar(10)])], [])],
    'a rule object handed a value nothing can read' => [static fn (): ?object => CastRuleObject::build(ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::image', [])->withChainedCall('dimensions', [ConstValue::instance(OpaqueCheck::class, [])]))],
    // Each of these would run the application's code.
    'a macro' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::date', [], [['method' => 'recorded', 'args' => []]])],
    'a macro called through __call()' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::date', [], [['method' => '__call', 'args' => [ConstValue::scalar('recorded'), ConstValue::array([])]]])],
    'a callable named by a string' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::string', [], [['method' => 'when', 'args' => [ConstValue::scalar(true), ConstValue::scalar(CallRecorder::class.'::record')]]])],
    'a model named as a table, with new' => [static fn (): ?array => castNew('Illuminate\\Validation\\Rules\\Exists', [ConstValue::scalar(RecordedModel::class)], [])],
    'a model named as a table, by the factory' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::unique', [ConstValue::scalar(RecordedModel::class)], [])],
    'the password the application registered' => [static fn (): ?object => CastRuleObject::build(ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::defaults', []))],
    'the password the application registered, required' => [static fn (): ?object => CastRuleObject::build(ConstValue::descriptor('Illuminate\\Validation\\Rules\\Password::required', []))],
    'the file the application registered' => [static fn (): ?object => CastRuleObject::build(ConstValue::descriptor('Illuminate\\Validation\\Rules\\File::default', []))],
    'an application\'s own file rule' => [static fn (): ?object => CastRuleObject::build(ConstValue::instance(RecordedUpload::class, []))],
    // Not one Laravel reads as a string.
    'a check Laravel runs' => [static fn (): ?array => castNew('Illuminate\\Validation\\Rules\\Password', [ConstValue::scalar(8)], [])],
    'an application rule' => [static fn (): ?array => castNew(OpaqueCheck::class, [], [])],
    'a macro on Rule' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::optionalSlug', [], [])],
    'a static of another class' => [static fn (): ?array => castFactory('App\\Support\\Rule::string', [], [])],
    'a chained call that returns no rule' => [static fn (): ?array => castFactory('Illuminate\\Validation\\Rule::string', [], [['method' => '__toString', 'args' => []]])],
]);

it('runs a callable that is no literal of the build: the guard is the reason none runs above', function (): void {
    // The macro and the callable above are refused by the build alone; called directly, each runs.
    Date::macro('recorded', function (): Date {
        CallRecorder::record();

        return $this;
    });

    (new Date)->recorded();
    expect(CallRecorder::$calls)->toBe(1);

    (new Date)->__call('recorded', []);
    expect(CallRecorder::$calls)->toBe(2);

    (new StringRule)->when(true, CallRecorder::class.'::record');
    expect(CallRecorder::$calls)->toBe(3);

    new Exists(RecordedModel::class);
    expect(CallRecorder::$calls)->toBeGreaterThan(3);

    $calls = CallRecorder::$calls;
    Password::defaults(static fn (): Password => CallRecorder::record(Password::min(12)));
    Password::defaults();
    expect(CallRecorder::$calls)->toBe($calls + 1);

    new RecordedUpload;
    expect(CallRecorder::$calls)->toBe($calls + 2);
});

it('builds every static of a rule read by its state that reads nothing registered, and no other', function (): void {
    // Read off the installed classes: a static whose body reaches the registered default answers with the
    // application's code, so it is never called; every other one builds from its arguments as PHP does.
    $samples = ['size' => 8, 'mimetypes' => ['pdf'], 'allowSvg' => false];
    $built = 0;
    foreach ([Password::class, File::class, Email::class] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_STATIC) as $static) {
            // A trait's statics (`macro()`, `flushMacros()`) build no rule.
            if (! $static->isPublic() || $static->getFileName() !== $file) {
                continue;
            }

            $lines = file((string) $static->getFileName()) ?: [];
            $body = implode('', array_slice($lines, (int) $static->getStartLine() - 1, (int) $static->getEndLine() - (int) $static->getStartLine() + 1));
            $values = array_map(static fn (ReflectionParameter $parameter): mixed => $samples[$parameter->getName()] ?? null, $static->getParameters());
            $written = ConstValue::descriptor($class.'::'.$static->getName(), array_map(static fn (mixed $value): ConstValue => is_array($value) ? ConstValue::array(array_map(ConstValue::scalar(...), $value)) : ConstValue::scalar($value), $values));

            if (preg_match('/default|static::\$/i', $body) === 1) {
                expect(CastRuleObject::build($written))->toBeNull($class.'::'.$static->getName());

                continue;
            }

            $built++;
            expect(CastRuleObject::build($written))->toEqual($static->invoke(null, ...$values), $class.'::'.$static->getName());
        }
    }

    // Password::min(), File::image() and File::types(), on every Laravel this supports.
    expect($built)->toBeGreaterThanOrEqual(3);
});
