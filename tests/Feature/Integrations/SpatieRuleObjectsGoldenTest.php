<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RuleObjectChoicesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RuleObjectChoicesData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/*
 * Framework rule objects handed to Spatie Data's validation attributes, locked in emitted bytes. No golden
 * held a Data property whose rule is an object rather than a string.
 */
function spatieRuleObjectsBuild(): GenerationResult
{
    // spatie/laravel-data's `#[Enum]` takes `only`/`except` from 4.x; before it no application can write one.
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(Enum::class, '__construct'))->getParameters(),
    );
    if (! in_array('only', $parameters, true)) {
        test()->markTestSkipped('The installed spatie/laravel-data takes no `only` in #[Enum].');
    }

    bootLaravelData(null);

    $string = ScalarT::string();
    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            RuleObjectChoicesData::class => new ClassMetadata(RuleObjectChoicesData::class, [
                new PropertyMetadata('status', $string),
                new PropertyMetadata('size', $string),
                new PropertyMetadata('priority', ScalarT::int()),
                new PropertyMetadata('code', $string),
                new PropertyMetadata('stage', $string),
                new PropertyMetadata('phase', $string),
                new PropertyMetadata('state', $string),
                new PropertyMetadata('colour', $string),
                new PropertyMetadata('mark', $string),
                new PropertyMetadata('note', UnionT::of([$string, new NullT])),
                new PropertyMetadata('consent', UnionT::of([ScalarT::bool(), $optional])),
                new PropertyMetadata('agreed', UnionT::of([ScalarT::bool(), $optional])),
                new PropertyMetadata('pair', UnionT::of([$string, $optional])),
                new PropertyMetadata('quoted', UnionT::of([$string, $optional])),
            ]),
        ],
        analysisOverrides: [
            RuleObjectChoicesController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-rule-objects', [RuleObjectChoicesController::class, 'store']),
        $engine,
    );
}

it('emits Spatie attributes handing over rule objects byte-identical to their committed golden', function (): void {
    assertGolden('spatie-rule-objects.uir.json', (new UirEmitter)->emit(spatieRuleObjectsBuild()->document));
});

/*
 * Each row says whether the document takes the body. It never takes one laravel-data refuses; a row marked
 * `loose` is one the server may take where the document does not, in another type's spelling. Every other
 * row the two answer alike.
 */
it('takes every value laravel-data takes through a rule object, and refuses the rest', function (array $fields, bool $published, bool $loose): void {
    $document = json_decode((new UirEmitter)->emit(spatieRuleObjectsBuild()->document), flags: JSON_THROW_ON_ERROR);
    $document->{'$ref'} = '#/components/schemas/RuleObjectChoicesDataRequest';

    $body = [
        'status' => 'draft', 'size' => 'small', 'priority' => 1, 'code' => 'new', 'stage' => 'draft',
        'phase' => 'draft', 'state' => 'published', 'colour' => 'red', 'mark' => 'ok', ...$fields,
    ];

    // The server's answer, by the rules laravel-data itself hands Laravel.
    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);
    $accepted = Validator::make($body, RuleObjectChoicesData::getValidationRules($body))->passes();

    expect((new SchemaValidator)->validate(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR), $document)->isValid())->toBe($published);

    if ($published || ! $loose) {
        expect($accepted)->toBe($published);
    }
})->with([
    'every field as listed' => [[], true, false],
    // `#[Rule(new Enum(…))]` takes the enum's cases and nothing else.
    'a status the enum has' => [['status' => 'archived'], true, false],
    'a status it has not' => [['status' => 'retired'], false, false],
    // `new In`/`new NotIn` inside a `#[Rule]` list hold the values they were built with.
    'a listed size' => [['size' => 'large'], true, false],
    'a size not listed' => [['size' => 'medium'], false, false],
    'an excluded code' => [['code' => 'legacy'], false, false],
    // An int-backed enum beside the integer the property declares.
    'a priority the enum has' => [['priority' => 10], true, false],
    'a priority it has not' => [['priority' => 2], false, false],
    'a priority sent as a string' => [['priority' => '5'], false, true],
    // An `#[Enum]` given the rule object validates with that object.
    'a stage the enum has' => [['stage' => 'archived'], true, false],
    'a stage it has not' => [['stage' => 'retired'], false, false],
    // `only` and `except` narrow the cases an `#[Enum]` takes, so a case left out is refused.
    'a phase only allows' => [['phase' => 'published'], true, false],
    'a phase only leaves out' => [['phase' => 'archived'], false, false],
    'a state except leaves out' => [['state' => 'archived'], false, false],
    // An `#[In]`/`#[NotIn]` given the rule object validates with that object.
    'a listed colour' => [['colour' => 'blue'], true, false],
    'a colour not listed' => [['colour' => 'green'], false, false],
    'an excluded mark' => [['mark' => 'void'], false, false],
    // `new ExcludeIf(false)` excludes nothing, so the field's other rules still hold.
    'a short note' => [['note' => 'abc'], true, false],
    'a note past its maximum' => [['note' => 'abcd'], false, false],
    // An `#[In([true, false])]` builds `in:"1",""`: the strings PHP makes of the two booleans, so both pass.
    'consent given' => [['consent' => true], true, false],
    'consent refused' => [['consent' => false], true, false],
    'consent as a zero' => [['consent' => 0], false, false],
    // `#[NotIn([false])]` excludes `""`, which is how Laravel compares `false`.
    'an agreement' => [['agreed' => true], true, false],
    'a refusal to agree' => [['agreed' => false], false, false],
    // Each value is one entry of the list the attribute builds, comma and all.
    'a pair holding a comma' => [['pair' => 'a,b'], true, false],
    'half of that pair' => [['pair' => 'a'], false, false],
    // Laravel reads `in:"x,y",z` as CSV: the quotes enclose an entry, and are no part of it.
    'a quoted entry' => [['quoted' => 'x,y'], true, false],
    'a quoted entry with its quotes' => [['quoted' => '"x'], false, false],
]);
