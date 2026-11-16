<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PipedRuleController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PipedRuleData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Rule strings a Spatie Data class pipes together, locked in emitted bytes. No golden held a `#[Rule]` or
 * `rules()` string holding more than one rule.
 */
function spatiePipedRulesBuild(): GenerationResult
{
    bootLaravelData(null);

    $text = UnionT::of([ScalarT::string(), new ClassT(DataClassReflector::OPTIONAL)]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            PipedRuleData::class => new ClassMetadata(PipedRuleData::class, [
                new PropertyMetadata('piped', $text),
                new PropertyMetadata('listed', $text),
                new PropertyMetadata('pattern', $text),
                new PropertyMetadata('overridden', $text),
                new PropertyMetadata('overriddenPattern', $text),
            ]),
        ],
        analysisOverrides: [
            PipedRuleController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
        traceOverrides: [
            PipedRuleData::class.'::rules' => TraceScript::forMethod(
                (string) (new ReflectionClass(PipedRuleData::class))->getFileName(),
                PipedRuleData::class,
                'rules',
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-piped-rules', [PipedRuleController::class, 'store']),
        $engine,
    );
}

it('emits rule strings a Spatie Data class pipes together byte-identical to their committed golden', function (): void {
    assertGolden('spatie-piped-rules.uir.json', (new UirEmitter)->emit(spatiePipedRulesBuild()->document));
});

/*
 * Each row says whether the document takes the body, and laravel-data answers the same: it splits a `#[Rule]` or
 * `rules()` string at its pipes, bare or in a list, unless the string holds a regex, which it keeps whole.
 */
it('takes every value laravel-data takes through a piped rule string, and refuses the rest', function (array $body, bool $accepted): void {
    $document = json_decode((new UirEmitter)->emit(spatiePipedRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/PipedRuleDataRequest';

    // The server's answer, by the rules laravel-data itself hands Laravel.
    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);

    expect(Validator::make($body, PipedRuleData::getValidationRules($body))->passes())->toBe($accepted)
        ->and((new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($accepted);
})->with([
    'every field left out' => [[], true],
    // `#[Rule('string|max:3')]` is two rules, so the maximum holds.
    'a piped value at its maximum' => [['piped' => 'abc'], true],
    'a piped value past it' => [['piped' => 'abcd'], false],
    // `#[Rule(['string|max:3', 'min:2'])]` splits the string inside the list too.
    'a listed value between its bounds' => [['listed' => 'ab'], true],
    'a listed value under its minimum' => [['listed' => 'a'], false],
    'a listed value past its maximum' => [['listed' => 'abcd'], false],
    // A regex's own `|` is no rule boundary.
    'one side of the pattern' => [['pattern' => 'ab'], true],
    'the other side' => [['pattern' => 'cd'], true],
    'neither side' => [['pattern' => 'ac'], false],
    // A `rules()` override reads the same way, in a list or as the field's whole string.
    'an overridden value at its maximum' => [['overridden' => 'abc'], true],
    'an overridden value past it' => [['overridden' => 'abcd'], false],
    'one side of the overriding pattern' => [['overriddenPattern' => 'cd'], true],
    'neither side of it' => [['overriddenPattern' => 'ac'], false],
]);
