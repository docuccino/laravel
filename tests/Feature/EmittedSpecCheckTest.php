<?php

declare(strict_types=1);

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * What the emitted-artifact check does to a real build: it says nothing about the workbench, it fires
 * when an overlay writes something the format cannot carry, and the export exits non-zero for it.
 *
 * The overlay is the point rather than a convenience. The check exists for defects in OUR emitter, and
 * the only way an application reaches one on purpose is by writing into the document itself — which is
 * also the one cause the diagnostic's help tells the reader to look at first. So this measures the
 * firing population from the side an application can actually reach.
 */
beforeEach(function (): void {
    $this->overlayDir = sys_get_temp_dir().'/docuccino-spec-check-'.uniqid();
    mkdir($this->overlayDir);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->overlayDir.'/*') ?: []);
    @rmdir($this->overlayDir);
});

/** An overlay that puts one `operationId` on two operations, after the assembler has been and gone. */
function collidingIdOverlay(string $dir): void
{
    file_put_contents($dir.'/collide.yaml', <<<'YAML'
        overlay: 1.0.0
        info:
          title: Collide
          version: 1.0.0
        actions:
          - target: $.paths['/api/forms'].get
            update:
              operationId: OverlayCollision
          - target: $.paths['/api/ping'].get
            update:
              operationId: OverlayCollision
        YAML);

    setBuild('documents.default.overlays', [$dir.'/*.yaml']);
}

/** An overlay that hangs a `$ref` on the document naming a component nothing defines. */
function danglingRefOverlay(string $dir): void
{
    file_put_contents($dir.'/dangling.yaml', <<<'YAML'
        overlay: 1.0.0
        info:
          title: Dangling
          version: 1.0.0
        actions:
          - target: $.components.schemas
            update:
              Dangling:
                $ref: '#/components/schemas/NobodyDefinesThis'
        YAML);

    setBuild('documents.default.overlays', [$dir.'/*.yaml']);
}

/** The `default` document built the way the commands build it, so the configured overlays are applied. */
function specCheckBuild(): GenerationResult
{
    return app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());
}

it('says nothing about the workbench document at any OpenAPI version', function (string $format): void {
    $document = specCheckBuild()->document;
    $report = Formats::emit($format, $document, new EmitOptions)->report;

    expect(diagnosticsCoded($report->diagnostics, 'document.openapi-invalid'))->toBe([])
        // Anti-vacuity: a document with no paths would agree with anything.
        ->and($document->toArray()['paths'])->not->toBeEmpty();
})->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

/**
 * The shape a real overlay defect takes: a component pointing at a name nothing defines, because the
 * component it named was renamed or removed. Every meta-schema accepts it — a `$ref` in an instance is
 * an ordinary string member — so the reference walk is the only thing that sees it.
 */
it('reports an overlay-written $ref that names nothing, at every OpenAPI version', function (string $format): void {
    danglingRefOverlay($this->overlayDir);

    $report = Formats::emit($format, specCheckBuild()->document, new EmitOptions)->report;
    $found = diagnosticsCoded($report->diagnostics, 'document.openapi-invalid');

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('#/components/schemas/NobodyDefinesThis')
        ->and($found[0]->help)->toContain('overlay');
})->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

/**
 * A guard is worth what it does, not what it says: the export writes the file and then exits non-zero,
 * with the diagnostic printed. `--fail-on` is untouched here on purpose — it is how strict the reader
 * wants to be about what the document SAYS, and a file that is not a valid document of its own format
 * is a different question, which is how `docuccino:validate` already treats the UIR half.
 */
it('writes the artifact and fails the export when it is not valid', function (): void {
    bindStubEngine();
    danglingRefOverlay($this->overlayDir);

    $out = sys_get_temp_dir().'/docuccino-spec-check-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])
        ->expectsOutputToContain('document.openapi-invalid')
        ->assertFailed();

    // Written, not withheld: a partial answer the reader can look at beats no answer at all, and the
    // exit code is what tells CI.
    expect(is_file($out))->toBeTrue()
        ->and((string) file_get_contents($out))->toContain('NobodyDefinesThis');

    @unlink($out);
});

it('exits zero for the same export with nothing wrong with the artifact', function (): void {
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-spec-check-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])->assertSuccessful();

    @unlink($out);
});

/**
 * The viewer's own seam. Its log line picks a level from the report, and it asked "are there any
 * WARNINGS" — exactly, so the loudest thing an emitter can now say would have gone out at info.
 */
it('logs a viewer serve at warning level when the artifact is invalid', function (): void {
    bindStubEngine();
    danglingRefOverlay($this->overlayDir);

    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    Log::spy();

    $this->get('/docs/api.json')->assertOk();

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('info');
});

/**
 * Cold and warm must report the same thing. A diagnostic raised WHILE BUILDING is lost on a warm
 * fragment-cache hit unless it travels on the fragment; this one is raised while EMITTING, after the
 * fragments have been assembled, so no cached answer can stand in for it — which is a claim worth
 * executing rather than asserting, because "the cache cannot reach it" is exactly what was believed
 * about the diagnostics that did go missing.
 */
it('reports the same findings on a warm build as on a cold one', function (): void {
    danglingRefOverlay($this->overlayDir);

    $dir = fragmentCacheDir('spec-check');

    try {
        $emit = static function (?CountingTypeEngine &$engine = null): array {
            app()->forgetScopedInstances();
            $engine = new CountingTypeEngine(WorkbenchEngine::make());
            $result = (new OpenApi32Emitter)->emitWithReport(
                app(DocumentBuilder::class)->build('default', $engine)->document,
                new EmitOptions,
            );

            return [
                $result->output,
                array_map(static fn ($d): string => $d->code.' '.$d->message, $result->report->diagnostics),
            ];
        };

        [$coldBytes, $coldFindings] = $emit($cold);

        // An unwritten cache would make the second build a second cold one and the row would prove
        // nothing; a second build that still reached the engine would have REBUILT the finding rather
        // than replayed past it, which is the way this assertion goes vacuous.
        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        [$warmBytes, $warmFindings] = $emit($warm);

        expect($warm)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($cold)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($cold->analyzeCount)->toBeGreaterThan(0)
            ->and($warm->analyzeCount)->toBe(0)
            ->and($warmFindings)->toBe($coldFindings)
            ->and($warmBytes)->toBe($coldBytes)
            ->and(array_filter($coldFindings, static fn (string $f): bool => str_starts_with($f, 'document.openapi-invalid')))
            ->toHaveCount(1);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

/** And the check changes no byte of what is written — it reads the serialisation, it does not make it. */
it('emits the same bytes it emitted before anything validated them', function (): void {
    bindStubEngine();

    $document = generateDocument()->document;

    assertGolden('workbench.openapi.json', (new OpenApi32Emitter)->emit($document));
});

/**
 * The population `document.duplicate-operation-id` was split out for. `representation.operation_id:
 * controller-method` is shipped and documented, and in this workbench it mints 29 ids of which 20 are
 * distinct — nine routes reach `FormController@index`. So the duplicate is the application's route
 * table plus a documented setting, and it is the one finding in this check that is.
 *
 * What it must therefore not do: exit the export non-zero, or tell the reader that Docuccino is
 * broken. What it must still do: say it, because the artifact really is out of spec.
 */
it('warns rather than fails when a documented id strategy collides two routes', function (string $format): void {
    setBuild('documents.default.representation.operation_id', 'controller-method');

    $result = specCheckBuild();
    $report = Formats::emit($format, $result->document, new EmitOptions)->report;

    // Anti-vacuity: the setting really did produce collisions to report.
    expect(diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'))->not->toBeEmpty()
        ->and(diagnosticsCoded($report->diagnostics, 'document.openapi-invalid'))->toBe([])
        ->and(diagnosticsCoded($report->diagnostics, 'document.duplicate-operation-id'))->not->toBeEmpty();
})->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

/**
 * And the export, which is where the misattribution was fatal: a run that writes an artifact its own
 * config asked for must not fail. Executed rather than reasoned about, because the gate is a severity
 * filter over the emit report and the only thing standing between it and a non-zero exit is that this
 * diagnostic is a warning.
 */
it('exits zero exporting with the documented controller-method id strategy', function (): void {
    bindStubEngine();
    setBuild('documents.default.representation.operation_id', 'controller-method');

    $out = sys_get_temp_dir().'/docuccino-spec-check-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])
        ->expectsOutputToContain('document.duplicate-operation-id')
        ->assertSuccessful();

    @unlink($out);
});

/**
 * Why the finding is kept at all rather than left to `route.duplicate-operation-id`. An overlay writes
 * into the finished document, long after the assembler has compared its fragments, so a collision it
 * causes is invisible to every other diagnostic in the build. This is the only thing that sees it.
 */
it('reports a duplicate an overlay wrote, which no route-level diagnostic can see', function (): void {
    collidingIdOverlay($this->overlayDir);

    $result = specCheckBuild();
    $found = diagnosticsCoded(
        Formats::emit('openapi-3.2', $result->document, new EmitOptions)->report->diagnostics,
        'document.duplicate-operation-id',
    );

    expect(diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'))->toBe([])
        ->and($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('OverlayCollision');
});

/**
 * The check reads what is actually WRITTEN, so the YAML target is checked in YAML. Proved through the
 * export, which is the seam where the bytes reach a file: the same overlay defect, the same finding,
 * against a serialisation nothing used to look at.
 */
it('checks a YAML export in YAML', function (): void {
    bindStubEngine();
    danglingRefOverlay($this->overlayDir);

    $out = sys_get_temp_dir().'/docuccino-spec-check-'.uniqid().'.yaml';

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--yaml' => true, '--out' => $out])
        ->expectsOutputToContain('document.openapi-invalid')
        ->assertFailed();

    expect(is_file($out))->toBeTrue()
        // Written as YAML, not JSON — otherwise the row above says nothing about the YAML carrier.
        ->and((string) file_get_contents($out))->toContain('NobodyDefinesThis')
        ->and((string) file_get_contents($out))->not->toStartWith('{');

    @unlink($out);
});
