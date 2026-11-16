<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArrayChoicesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArrayChoicesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedArrayChoicesData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Choice, size and uniqueness attributes on a Spatie Data class's array-typed properties, locked in emitted
 * bytes. No golden held a Data property typed as an array beside a choice rule.
 */
function spatieArrayChoicesBuild(): GenerationResult
{
    bootLaravelData(null);

    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $list = UnionT::of([new ListT(ScalarT::string()), $optional]);
    $map = UnionT::of([new MapT(ScalarT::string(), ScalarT::string()), $optional]);
    $trace = static fn (string $class): callable => TraceScript::forMethod((string) (new ReflectionClass($class))->getFileName(), $class, 'rules');
    $returns = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))]);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ArrayChoicesData::class => new ClassMetadata(ArrayChoicesData::class, [
                new PropertyMetadata('tags', $list),
                new PropertyMetadata('blocked', $list),
                new PropertyMetadata('labels', $map),
                new PropertyMetadata('banned', $map),
                new PropertyMetadata('maybe', UnionT::of([new ListT(ScalarT::string()), new NullT])),
                new PropertyMetadata('statuses', $list),
                new PropertyMetadata('ruled', $list),
                new PropertyMetadata('few', $list),
                new PropertyMetadata('capped', $map),
                new PropertyMetadata('unique', $list),
                new PropertyMetadata('overridden', $list),
            ]),
            MergedArrayChoicesData::class => new ClassMetadata(MergedArrayChoicesData::class, [
                new PropertyMetadata('merged', $list),
            ]),
        ],
        analysisOverrides: [
            ArrayChoicesController::class.'::store' => $returns,
            ArrayChoicesController::class.'::merge' => $returns,
        ],
        traceOverrides: [
            ArrayChoicesData::class.'::rules' => $trace(ArrayChoicesData::class),
            MergedArrayChoicesData::class.'::rules' => $trace(MergedArrayChoicesData::class),
        ],
    );

    return localityBuild(
        static function (Router $router): void {
            $router->post('api/zz-array-choices', [ArrayChoicesController::class, 'store']);
            $router->post('api/zz-array-choices/merged', [ArrayChoicesController::class, 'merge']);
        },
        $engine,
    );
}

it('emits choice attributes on Spatie Data array properties byte-identical to their committed golden', function (): void {
    assertGolden('spatie-array-choices.uir.json', (new UirEmitter)->emit(spatieArrayChoicesBuild()->document));
});

/*
 * Each row says whether laravel-data takes the body, by the rules it hands Laravel, and the document answers the
 * same. laravel-data adds `array` to an array-typed property, and beside it Laravel compares an `in` or `not_in`
 * with each element of the value: `#[In('a', 'b')]` takes `["a"]` and `[]` and refuses `["c"]`, and
 * `#[NotIn('a', 'b')]` refuses exactly those. A map's values are its elements. A `rules()` override replaces
 * the `array` rule with the rest, unless the class merges its rules.
 */
it('takes every array laravel-data takes through a choice attribute, and refuses the rest', function (string $class, array $body, bool $accepted): void {
    $document = json_decode((new UirEmitter)->emit(spatieArrayChoicesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/'.class_basename($class).'Request';

    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);

    expect(Validator::make($body, $class::getValidationRules($body))->passes())->toBe($accepted)
        ->and((new SchemaValidator)->validate(json_decode((string) json_encode((object) $body), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($accepted);
})->with([
    'every field left out' => [ArrayChoicesData::class, [], true],
    'a listed element' => [ArrayChoicesData::class, ['tags' => ['a']], true],
    'every element listed' => [ArrayChoicesData::class, ['tags' => ['a', 'b']], true],
    'no elements' => [ArrayChoicesData::class, ['tags' => []], true],
    'an element not listed' => [ArrayChoicesData::class, ['tags' => ['c']], false],
    'one element of two not listed' => [ArrayChoicesData::class, ['tags' => ['a', 'c']], false],
    'a listed value that is no array' => [ArrayChoicesData::class, ['tags' => 'a'], false],
    'every element excluded' => [ArrayChoicesData::class, ['blocked' => ['a']], false],
    'no elements, which are all excluded' => [ArrayChoicesData::class, ['blocked' => []], false],
    'an element not excluded' => [ArrayChoicesData::class, ['blocked' => ['c']], true],
    'one element of two excluded' => [ArrayChoicesData::class, ['blocked' => ['a', 'c']], true],
    'a map whose value is listed' => [ArrayChoicesData::class, ['labels' => ['k' => 'a']], true],
    'a map whose value is not listed' => [ArrayChoicesData::class, ['labels' => ['k' => 'a', 'j' => 'c']], false],
    'a map whose every value is excluded' => [ArrayChoicesData::class, ['banned' => ['k' => 'a']], false],
    'a map with a value not excluded' => [ArrayChoicesData::class, ['banned' => ['k' => 'a', 'j' => 'c']], true],
    'null beside nullable' => [ArrayChoicesData::class, ['maybe' => null], true],
    'a listed element beside nullable' => [ArrayChoicesData::class, ['maybe' => ['b']], true],
    'an element not listed beside nullable' => [ArrayChoicesData::class, ['maybe' => ['c']], false],
    // `#[Rule('in:a,b')]` reads as `#[In]` does.
    'a listed element through #[Rule]' => [ArrayChoicesData::class, ['ruled' => ['a']], true],
    'an element not listed through #[Rule]' => [ArrayChoicesData::class, ['ruled' => ['c']], false],
    // An enum rule compares the whole value with the cases, whatever the `array` beside it, and no array is one.
    'a list of cases' => [ArrayChoicesData::class, ['statuses' => ['open']], false],
    'no cases' => [ArrayChoicesData::class, ['statuses' => []], false],
    // A size counts an array's elements, a list's or a map's.
    'a list at its maximum' => [ArrayChoicesData::class, ['few' => ['a', 'b']], true],
    'a list past it' => [ArrayChoicesData::class, ['few' => ['a', 'b', 'c']], false],
    'a map at its maximum' => [ArrayChoicesData::class, ['capped' => ['k' => 'a']], true],
    'a map past it' => [ArrayChoicesData::class, ['capped' => ['k' => 'a', 'j' => 'b']], false],
    // `distinct` on the array's own key compares it with no other value, so a repeat passes.
    'a repeat beside distinct' => [ArrayChoicesData::class, ['unique' => ['a', 'a']], true],
    // The override's `in` stands without `array`, so it compares the value, and no array matches.
    'a listed element under an override' => [ArrayChoicesData::class, ['overridden' => ['a']], false],
    'a listed value under an override' => [ArrayChoicesData::class, ['overridden' => 'a'], true],
    // Merged, the override's `in` stands beside the inferred `array`.
    'a listed element under a merged override' => [MergedArrayChoicesData::class, ['merged' => ['a']], true],
    'an element not listed under a merged override' => [MergedArrayChoicesData::class, ['merged' => ['c']], false],
]);
