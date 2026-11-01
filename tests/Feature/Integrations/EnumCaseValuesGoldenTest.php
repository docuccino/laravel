<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\EnumCaseValues\GadgetFilterController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\EnumCaseChoicesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\EnumCaseChoicesData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Spatie\LaravelData\Attributes\Validation\In;

/*
 * Enum cases where a value goes, outside FormRequest rules, locked in emitted bytes: Spatie Data's
 * `#[In]`/`#[NotIn]` naming cases, and a Query Builder filter defaulting to a case. Each population had
 * no golden at all, so a change to what either publishes moved nothing.
 */
it('emits Spatie choice attributes naming enum cases byte-identical to their committed golden', function (): void {
    $takes = (new ReflectionParameter([In::class, '__construct'], 0))->getType();
    $types = $takes instanceof ReflectionUnionType ? $takes->getTypes() : [$takes];

    // spatie/laravel-data takes a case in these attributes from 4.21; before it no application can write one.
    if (! in_array(UnitEnum::class, array_map(static fn ($type): string => $type instanceof ReflectionNamedType ? $type->getName() : '', $types), true)) {
        $this->markTestSkipped('The installed spatie/laravel-data takes no enum case in #[In].');
    }

    bootLaravelData(null);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            EnumCaseChoicesData::class => new ClassMetadata(EnumCaseChoicesData::class, [
                new PropertyMetadata('schedule', ScalarT::string()),
                new PropertyMetadata('priority', ScalarT::int()),
                new PropertyMetadata('channel', ScalarT::string()),
                new PropertyMetadata('phase', ScalarT::string()),
                new PropertyMetadata('status', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            EnumCaseChoicesController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->post('api/zz-enum-case-choices', [EnumCaseChoicesController::class, 'store']),
        $engine,
    );

    assertGolden('spatie-enum-case-choices.uir.json', (new UirEmitter)->emit($result->document));
});

it('emits a Query Builder filter defaulting to an enum case byte-identical to its committed golden', function (): void {
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        traceOverrides: [
            GadgetFilterController::class.'::index' => TraceScript::forMethod(
                (string) (new ReflectionClass(GadgetFilterController::class))->getFileName(),
                GadgetFilterController::class,
                'index',
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-gadget-filters', [GadgetFilterController::class, 'index']),
        $engine,
    );

    assertGolden('qb-enum-case-default.uir.json', (new UirEmitter)->emit($result->document));
});
