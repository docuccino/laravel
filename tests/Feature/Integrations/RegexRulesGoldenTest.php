<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\RegexRules\AddressController;
use Docuccino\Laravel\Tests\Fixtures\RegexRules\StoreAddressRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * `regex:` rules locked in emitted bytes, in every format. Under `/i` the server takes every case of a
 * letter, and under `/u` a class escape or property takes every script, so the published pattern spells
 * those out; and a consumer may compile it with ECMA-262's `u` flag or without (OpenAPI 3.0 has none), so
 * it must take every value the server does either way.
 */
beforeEach(function (): void {
    $this->build = static function () {
        app()->forgetScopedInstances();

        /** @var Router $router */
        $router = app('router');
        $router->setRoutes(new RouteCollection);
        $router->post('api/zz-addresses', [AddressController::class, 'store']);

        app()->instance(TypeEngine::class, WorkbenchEngine::make(
            analysisOverrides: [AddressController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
            traceOverrides: [StoreAddressRequest::class.'::rules' => TraceScript::forMethod(
                (string) (new ReflectionClass(StoreAddressRequest::class))->getFileName(),
                StoreAddressRequest::class,
                'rules',
            )],
        ));

        return generateDocument();
    };

    $this->properties = function (): array {
        $document = json_decode((new UirEmitter)->emit(($this->build)()->document), flags: JSON_THROW_ON_ERROR);

        return (array) $document->components->schemas->StoreAddressRequest->properties;
    };

    // Values the server accepts for each field, and their neighbours one character away.
    $this->samples = [
        'postcode' => ['SW1A 1AA', 'sw1a 1aa', 'M1 1AE', 'm11ae', 'Ec1a1Bb'],
        'colour' => ['#fff', '#FFF', '#a1B2c3', "#abc\n"],
        'unit' => ['kelvin', 'KELVIN', "\u{212A}elvin", "\u{17F}econd", 'Ks'],
        'recipient' => ['Anne-Marie', 'Zoë Smith', 'Ωμέγα', "名前\u{3000}名前", "Jo\u{85}Ann", "Jo\tAnn"],
        'initials' => ['JFK', 'jfk', 'ÉA', 'Éé'],
        'full_name' => ["Anne-Marie O'Neil", 'Zoë Smith', "名前\u{3000}名前"],
    ];

    // Where the document agrees with the server exactly over ASCII. A caseless `\p{Lu}` takes lowercase
    // letters on PCRE2 from 10.45 and not before, so its pattern takes both and is wider than one of them;
    // a field published without a pattern is wider than the server everywhere.
    $this->exact = ['postcode', 'colour', 'unit', 'recipient'];
});

it('emits regex rules byte-identical to their committed goldens', function (): void {
    $result = ($this->build)();

    assertGolden('regex-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('regex-rules.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('regex-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('never refuses a value the server accepts, and agrees with it exactly over ASCII', function (string $field): void {
    $schema = ($this->properties)()[$field];
    $rules = [$field => (new StoreAddressRequest)->rules()[$field]];

    // Each sample, and each with one ASCII character put in front, behind, or in place of its first.
    $values = [];
    foreach ($this->samples[$field] as $sample) {
        $values[] = $sample;
        for ($code = 0; $code < 128; $code++) {
            array_push($values, chr($code).$sample, $sample.chr($code), chr($code).mb_substr($sample, 1));
        }
    }

    $refused = [];
    $disagreements = [];
    foreach ($values as $value) {
        $accepted = Validator::make([$field => $value], $rules)->passes();
        $published = (new SchemaValidator)->validate($value, $schema)->isValid();
        if ($accepted && ! $published) {
            $refused[] = $value;
        }
        if (mb_check_encoding($value, 'ASCII') && $accepted !== $published) {
            $disagreements[] = $value;
        }
    }

    // A value the server takes must pass the document; beyond ASCII the document may be wider, and true.
    expect($refused)->toBe([])
        ->and(in_array($field, $this->exact, true) ? $disagreements : [])->toBe([]);
})->with(['postcode', 'colour', 'unit', 'recipient', 'initials', 'full_name']);

it('publishes patterns that take every accepted value with or without the unicode flag', function (): void {
    $properties = ($this->properties)();
    $fields = array_keys($this->samples);
    sort($fields);
    expect(array_keys($properties))->toBe($fields);

    foreach ($this->samples as $field => $samples) {
        if ($field === 'full_name') {
            continue;
        }
        $pattern = (string) $properties[$field]->pattern;
        foreach ($samples as $sample) {
            // Without `u` ECMA-262 reads a character outside the BMP as two UTF-16 units, each non-ASCII.
            $units = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{E000}\u{E001}", $sample);
            expect(preg_match('/(*UTF)'.str_replace('/', '\/', $pattern).'/D', $sample))->toBe(1, "{$pattern} on {$sample}")
                ->and(preg_match('/(*UTF)'.str_replace('/', '\/', $pattern).'/D', $units))->toBe(1, "{$pattern} without u on {$sample}");
        }
    }
});

it('publishes no pattern a consumer would backtrack through exponentially, and says so', function (): void {
    // Widened, `\s` and `[\pL'-]` both take every non-ASCII character, so the repeated group would split a
    // run of them every way before failing; the field is published as the string it is.
    $result = ($this->build)();
    $properties = ($this->properties)();

    expect((array) $properties['full_name'])->toBe(['type' => 'string'])
        ->and(array_values(array_filter(
            array_map(static fn ($d): string => $d->code, $result->diagnostics),
            static fn (string $code): bool => str_starts_with($code, 'validation.regex'),
        )))->toBe(['validation.regex-unportable']);
});
