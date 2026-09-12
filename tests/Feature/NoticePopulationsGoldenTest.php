<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Daybook;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Depot;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Emblem;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Metronome;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Sandglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Signpost;
use Docuccino\Laravel\Tests\Support\RulesTraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\InlineValidationController;
use Workbench\App\Http\Controllers\ModelNoticesController;
use Workbench\App\Http\Controllers\NoticeFieldsController;
use Workbench\App\Http\Requests\SearchNoticesRequest;
use Workbench\App\Http\Requests\StoreNoticeRequest;

/*
 * Every notice below claims something about the DOCUMENT — this response has no columns, this field went
 * undocumented, this container was never decided — so which model or field earns one is a fact about a
 * whole build rather than about the class it names. Each document therefore carries the members that earn
 * a notice BESIDE the ones that must not, and pins the shapes they published with them: silencing the
 * firing half and silencing the dominant silent half are two different diffs here, not two passing tests.
 *
 * The routes are the harness's own ({@see localityBuild()}), so nothing committed churns and no unrelated
 * route can quiet a notice by accident.
 */

function modelNoticeEngine(): TypeEngine
{
    $location = new SourceLocation('');
    $returning = static fn (DType $type): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite($type, $location)]);
    $carbon = new ClassT('Carbon\\CarbonImmutable');
    $action = ModelNoticesController::class.'::';

    return WorkbenchEngine::make(
        callables: [
            Emblem::class.'::getBadgeAttribute' => $returning(ScalarT::string()),
            Sandglass::class.'::getPostedAtAttribute' => $returning($carbon),
            Depot::class.'::keeper' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', [new ClassT(Merchant::class)])),
        ],
        classOverrides: [
            // The ide-helper tags each fixture carries. Blank, Emblem and Depot are left unscripted,
            // which is their whole point: no column source speaks for them.
            Chronicle::class => new ClassMetadata(Chronicle::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('title', ScalarT::string()),
            ]),
            Daybook::class => new ClassMetadata(Daybook::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('title', ScalarT::string()),
            ]),
            Sandglass::class => new ClassMetadata(Sandglass::class, [
                new PropertyMetadata('posted_at', $carbon),
            ]),
            Signpost::class => new ClassMetadata(Signpost::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('label', ScalarT::string()),
            ]),
            Metronome::class => new ClassMetadata(Metronome::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('title', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            $action.'showBlank' => $returning(new ClassT(Blank::class)),
            $action.'showEmblem' => $returning(new ClassT(Emblem::class)),
            $action.'showDepot' => $returning(new ClassT(Depot::class)),
            $action.'showChronicle' => $returning(new ClassT(Chronicle::class)),
            $action.'showDaybook' => $returning(new ClassT(Daybook::class)),
            $action.'showSandglass' => $returning(new ClassT(Sandglass::class)),
            $action.'showSignpost' => $returning(new ClassT(Signpost::class)),
            $action.'showMetronome' => $returning(new ClassT(Metronome::class)),
        ],
    );
}

function modelNoticeDocument(): GenerationResult
{
    return localityBuild(static function (Router $router): void {
        // no-columns: the undocumented model, then the two whose only key an append and an eager load add.
        $router->get('api/model-notices/blank', [ModelNoticesController::class, 'showBlank']);
        $router->get('api/model-notices/appended', [ModelNoticesController::class, 'showEmblem']);
        $router->get('api/model-notices/eager-loaded', [ModelNoticesController::class, 'showDepot']);
        // custom-date-serialization: the model that lost a format, then the four the override never reaches.
        $router->get('api/model-notices/overridden-dates', [ModelNoticesController::class, 'showChronicle']);
        $router->get('api/model-notices/hidden-dates', [ModelNoticesController::class, 'showDaybook']);
        $router->get('api/model-notices/accessor-dates', [ModelNoticesController::class, 'showSandglass']);
        $router->get('api/model-notices/inherited-override', [ModelNoticesController::class, 'showSignpost']);
        $router->get('api/model-notices/self-formatted-dates', [ModelNoticesController::class, 'showMetronome']);
    }, static fn (): TypeEngine => modelNoticeEngine());
}

/**
 * The claim each golden makes, said out loud: a regeneration that emptied the artifact, or one that
 * widened a firing population, then names what went wrong instead of only differing.
 *
 * @param  list<Diagnostic>  $diagnostics
 * @param  string  $family  the code prefix this document is about
 * @param  list<string>  $codes  that family's notices as a MULTISET — the order the build reports
 *                               them is the golden's to pin, and a reporter that legitimately moves
 *                               when it raises would otherwise fail this claim for the wrong reason
 * @param  list<string>  $firing  what the notices must name
 * @param  list<string>  $silent  what no notice may name
 */
function expectNoticePopulation(array $diagnostics, string $family, array $codes, array $firing, array $silent): void
{
    $notices = array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_starts_with($diagnostic->code, $family),
    ));

    $reported = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $notices);
    sort($reported);
    sort($codes);

    expect($reported)->toBe($codes);

    $said = implode("\n", array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $notices));

    foreach ($firing as $named) {
        expect($said)->toContain($named);
    }

    foreach ($silent as $quiet) {
        expect($said)->not->toContain($quiet);
    }
}

it('emits the eloquent notice population and its document byte-identically', function (): void {
    $result = modelNoticeDocument();

    assertGolden('workbench-eloquent-notices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-eloquent-notices.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    expectNoticePopulation(
        $result->diagnostics,
        'eloquent.',
        ['eloquent.custom-date-serialization', 'eloquent.no-columns'],
        ['Chronicle overrides serializeDate()', '(created_at, published_at, updated_at)', 'Blank exposes no documentable columns'],
        ['Emblem', 'Depot', 'Daybook', 'Sandglass', 'Signpost', 'Metronome'],
    );
});

/**
 * The rules each fixture really writes, read by the real visitor off a mirror of its body — the suite
 * builds against the deterministic stub engine, and the fixture group proves the same recovery for real.
 *
 * @return array<string, callable(TraceVisitor): void>
 */
function fieldNoticeTraces(): array
{
    return [
        StoreNoticeRequest::class.'::rules' => RulesTraceScript::forPhp(<<<'PHP'
            return [
                'attachment' => [static fn (): bool => true],
                'secret' => [static fn (): bool => true],
                'status' => ['required', \Illuminate\Validation\Rule::in('any', ...$this->tiers())],
                'visibility' => ['required', \Illuminate\Validation\Rule::in('draft', ...$this->tiers())],
                'tags' => ['array'],
                'meta' => ['array'],
            ];
            PHP),
        SearchNoticesRequest::class.'::rules' => RulesTraceScript::forPhp(<<<'PHP'
            return [
                'marker' => [static fn (): bool => true],
                'secret' => [static fn (): bool => true],
                'scope' => ['array'],
                'window' => ['array'],
            ];
            PHP),
        InlineValidationController::class.'::store' => RulesTraceScript::forPhp(<<<'PHP'
            $request->validate([
                'title' => 'required|string',
                'payload' => [static fn (): bool => true],
                'secret' => [static fn (): bool => true],
            ]);
            PHP),
    ];
}

function fieldNoticeDocument(): GenerationResult
{
    return localityBuild(static function (Router $router): void {
        $router->post('api/field-notices', [NoticeFieldsController::class, 'store']);
        // The same three unreadable shapes on a read verb, where the rules become query parameters.
        $router->get('api/field-notices', [NoticeFieldsController::class, 'index']);
        // And the other declaration site for the same notice: an inline validate(), whose only
        // declarations are the ones on the action itself.
        $router->post('api/field-notices/inline', [InlineValidationController::class, 'store']);
    }, static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: fieldNoticeTraces()));
}

/**
 * A declaration writes a field from a layer above the recovery, and both verbs carry one: the body's
 * `#[BodyParameter]`, the query's `#[QueryParameter]`, and for an inline `validate()` only the action's
 * own. So each notice appears against the field nothing declared, beside the field a declaration answers
 * for, in both halves of the document the rules can land in.
 */
it('emits the validation notice population and its document byte-identically', function (): void {
    $result = fieldNoticeDocument();

    assertGolden('workbench-validation-notices.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-validation-notices.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    expectNoticePopulation(
        $result->diagnostics,
        'validation.',
        [
            'validation.rule-unrecoverable',
            'validation.rule-unrecoverable',
            'validation.rule-values-unread',
            'validation.container-undecided',
            'validation.container-undecided',
            'validation.rule-unrecoverable',
        ],
        ['"secret"', '"visibility"', '"window"', '"meta"', 'omitted from the query parameters', 'omitted from the request schema'],
        ['"attachment"', '"status"', '"tags"', '"marker"', '"scope"', '"payload"', '"title"'],
    );
});
