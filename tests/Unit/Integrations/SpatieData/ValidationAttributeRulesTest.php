<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\Rules\CallRecorder;
use Docuccino\Laravel\Tests\Fixtures\Rules\RecordedModel;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Attributes\Validation\ObjectValidationAttribute;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\Exceptions\CannotBuildValidationRule;
use Spatie\LaravelData\Support\Validation\RuleDenormalizer;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Workbench\App\Enums\WidgetStatus;

/*
 * The guard behind the reflector's reading of spatie validation attributes. laravel-data, not Docuccino, says
 * which rule an attribute stands for: it writes the attribute's own `keyword()` and `parameters()` into a rule
 * string, or hands Laravel the rule string or object an object attribute's `getRule()` builds
 * (`RuleDenormalizer`). So every attribute the installed package ships is built here, and what the reflector
 * hands over for it — its rule strings, then its rule objects — must be what laravel-data hands Laravel.
 *
 * An attribute is built with an argument for each parameter it requires, written by name and in reverse, so a
 * reading that took them in the order written rather than the order the attribute declares them fails too. An
 * object attribute requires none and refuses to build without one, so it is built from the sources below
 * instead: by name, by position, and both.
 */

it('reads every validation attribute laravel-data ships to the rule laravel-data hands Laravel for it', function (): void {
    $directory = dirname((string) (new ReflectionClass(ValidationAttribute::class))->getFileName());
    $denormalizer = app(RuleDenormalizer::class);
    $status = '\\'.WidgetStatus::class.'::class';

    // The shipped attributes read apart, each with why.
    $rows = [
        'Rule' => 'Its arguments are rule strings and objects already, split as laravel-data splits them.',
    ];

    $sources = [
        'Dimensions' => ['minWidth: 10', '10', '10, 20, maxWidth: 100', 'maxHeight: 50, minWidth: 10', 'ratio: "3/2"', 'rule: new \Illuminate\Validation\Rules\Dimensions(["width" => 5])'],
        // A table written as a model's class is read apart, below.
        'Exists' => ['"users"', '"users", "email"', 'column: "email", table: "users"', '"users", connection: "pgsql"', 'withoutTrashed: true, table: "users"', 'rule: new \Illuminate\Validation\Rules\Exists("users", "id")'],
        'Unique' => ['"users"', 'column: "handle", table: "users"', '"users", "email", ignore: "5"', 'ignoreColumn: "uuid", ignore: "5", table: "users"', 'rule: new \Illuminate\Validation\Rules\Unique("users")'],
        'Password' => ['', '10', 'min: 10, letters: true', '10, true, mixedCase: true', 'uncompromised: true, uncompromisedThreshold: 3', 'default: true', 'rule: new \Illuminate\Validation\Rules\Password(6)'],
        'Enum' => [$status, 'enum: '.$status, 'new \Illuminate\Validation\Rules\Enum('.$status.')'],
        'In' => ['"a", "b"', '["a", "b"]', 'new \Illuminate\Validation\Rules\In(["a"])'],
        'NotIn' => ['"a", "b"', '["a", "b"]', 'new \Illuminate\Validation\Rules\NotIn(["a"])'],
        'Required' => ['', 'new \Illuminate\Validation\Rules\RequiredIf(false)'],
        'Prohibited' => ['', 'new \Illuminate\Validation\Rules\ProhibitedIf(true)'],
        'Exclude' => ['', 'new \Illuminate\Validation\Rules\ExcludeIf(true)'],
    ];

    $compared = [];
    $apart = [];
    $wrong = [];
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        // The basename is the case-sensitive spelling spatie declares; PHP class names are not.
        $short = basename($file, '.php');
        $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.$short;
        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (isset($rows[$short])) {
            $apart[] = $short;

            continue;
        }

        // Every object attribute is built from sources of its own, so none is compared only where it refuses to build.
        expect(is_subclass_of($class, ObjectValidationAttribute::class))->toBe(isset($sources[$short]), $short);

        foreach ($sources[$short] ?? [attributeSampleArguments($class)[1]] as $source) {
            $carrier = attributeCarrier($class, $source);
            try {
                $written = $denormalizer->execute((new ReflectionProperty($carrier, 'value'))->getAttributes()[0]->newInstance(), ValidationPath::create());
            } catch (CannotBuildValidationRule) {
                // laravel-data refuses these arguments, so every request carrying the field fails, and no rule holds.
                $written = [];
            }

            $reflector = new DataClassReflector;
            $read = array_merge(...$reflector->attributeRules($carrier, 'value'));

            $compared[$short] = true;
            if (serialize($read) !== serialize($written)) {
                $wrong[] = sprintf('#[%s(%s)]: read %s, laravel-data hands over %s', $short, $source, print_r($read, true), print_r($written, true));
            }
        }
    }

    // A scan that matched nothing would agree with everything. spatie has shipped ~90 of these for the whole of v4.
    expect(count($compared))->toBeGreaterThanOrEqual(70)
        ->and($wrong)->toBe([])
        // Every row names an attribute the package ships, so none outlives its attribute.
        ->and(array_values(array_diff(array_keys($rows), $apart)))->toBe([])
        ->and(array_values(array_diff(array_keys($sources), array_keys($compared))))->toBe([]);
});

it('reads a password the application registers as its default by its keyword alone', function (): void {
    // That default is set at boot, in a file no build keys on, so a minimum read off it could go stale unseen.
    Password::defaults(static fn (): Password => Password::min(20));

    try {
        $carrier = attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\Password', 'default: true');
        $reflector = new DataClassReflector;

        expect($reflector->attributeRules($carrier, 'value')[0])->toBe(['password'])
            ->and($reflector->attributeRules($carrier, 'value')[1])->toBe([])
            // A rule object it is given wins over the default, in laravel-data as here.
            ->and((new DataClassReflector)->attributeRules(attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\Password', 'default: true, rule: new \Illuminate\Validation\Rules\Password(6)'), 'value')[1])
            ->toEqual([new Password(6)]);
    } finally {
        Password::$defaultCallback = null;
    }
});

it('reads a database rule whose table is a model\'s class by its keyword, never constructing the model', function (string $attribute, string $arguments, string $keyword): void {
    // Laravel resolves such a table by constructing the model, which runs the application's boot code; the
    // build runs none of it. `exists` and `unique` say the field names a stored record, whatever the table.
    CallRecorder::$calls = 0;
    $carrier = attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\'.$attribute, $arguments);
    $reflector = new DataClassReflector;

    expect($reflector->attributeRules($carrier, 'value')[0])->toBe([$keyword])
        ->and($reflector->attributeRules($carrier, 'value')[1])->toBe([])
        ->and(CallRecorder::$calls)->toBe(0);
})->with([
    '#[Exists(Model::class)]' => ['Exists', '\\'.RecordedModel::class.'::class', 'exists'],
    '#[Unique(table: Model::class, column: …)]' => ['Unique', 'column: "handle", table: \\'.RecordedModel::class.'::class', 'unique'],
]);

it('reads a parameter as laravel-data writes it', function (string $attribute, string $read): void {
    $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.explode('(', $attribute)[0];
    $carrier = attributeCarrier($class, substr($attribute, strlen(explode('(', $attribute)[0]) + 1, -1));

    expect((new DataClassReflector)->attributeRules($carrier, 'value')[0])->toBe([$read]);
})->with([
    // A case is the value Laravel compares, a bool is spelled out, and a null is left out.
    'a backed case' => ['RequiredIf("status", \Workbench\App\Enums\WidgetStatus::Published)', 'required_if:status,published'],
    'a bool' => ['AcceptedIf("terms", true)', 'accepted_if:terms,true'],
    'a null' => ['CurrentPassword(null)', 'current_password'],
    'a list' => ['ArrayType(["a", "b"])', 'array:a,b'],
    // A field named by reference is that field, as at the root of the request.
    'a field reference' => ['Same(new \Spatie\LaravelData\Support\Validation\References\FieldReference("other"))', 'same:other'],
    // Only a request resolves a route parameter, so the build states the rule without it.
    'a route parameter' => ['Max(new \Spatie\LaravelData\Support\Validation\References\RouteParameterReference("limit"))', 'max'],
]);

it('reads a field reference under the path laravel-data validates the declaring object at', function (string $attribute, string $path, string $item): void {
    // laravel-data resolves a reference under the path of the object declaring the property, so the rule it hands
    // Laravel there is the one to read. A list's items it validates one by one, at `ranges.0`, `ranges.1`, … —
    // the build documents them once, at `ranges.*`, which Laravel reads as the index of the item being validated.
    $class = 'Spatie\\LaravelData\\Attributes\\Validation\\'.explode('(', $attribute)[0];
    $carrier = attributeCarrier($class, substr($attribute, strlen(explode('(', $attribute)[0]) + 1, -1));
    $instance = (new ReflectionProperty($carrier, 'value'))->getAttributes()[0]->newInstance();
    $written = app(RuleDenormalizer::class)->execute($instance, ValidationPath::create(str_replace('*', $item, $path) ?: null));

    $read = (new DataClassReflector)->attributeRules($carrier, 'value', $path)[0];

    expect(str_replace($path, str_replace('*', $item, $path), $read))->toBe($written);
})->with([
    'at the root' => ['GreaterThan("min")', '', '0'],
    'in a nested object' => ['GreaterThan("min")', 'range', '0'],
    'in a list of objects' => ['GreaterThan("min")', 'ranges.*', '0'],
    'in a list nested in an object' => ['GreaterThan("min")', 'order.lines.*', '3'],
    // A reference written from the root names the same field wherever it is declared.
    'from the root, in a nested object' => ['LessThan(new \Spatie\LaravelData\Support\Validation\References\FieldReference("max", fromRoot: true))', 'range', '0'],
    // Every attribute taking a field writes it through the one reading, a presence condition included.
    'a presence condition in a nested object' => ['RequiredIf("kind", "business")', 'range', '0'],
]);

it('reads no rule from an attribute laravel-data cannot build either', function (): void {
    // laravel-data refuses this mode when it builds the rule, so every request carrying the field fails.
    expect((new DataClassReflector)->attributeRules(attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\Distinct', '"loose"'), 'value')[0])->toBe([]);
});
