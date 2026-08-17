<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;
use Docuccino\Laravel\Tests\Support\RouteNoteRecorder;
use Docuccino\Laravel\Tests\Support\ThrowingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;

/**
 * Diagnostics are embedded in the document, and the document is committed and hosted — so a machine
 * path in one breaks the byte-identical-output promise where it is hardest to notice. A thrown message
 * is the way in, and these rows fix that it never arrives whole.
 */
it('publishes no machine path in the diagnostic a failed route leaves behind', function (): void {
    bindStubEngine();

    // Two shapes a thrown message carries a path in: a project file, which relativises to something the
    // author can open, and a file under neither the project nor any package, which cannot.
    $inside = base_path('app/Http/Controllers/FormController.php');
    $outside = '/'.uniqid('docuccino-elsewhere-', true).'/vendor/acme/src/Reader.php';

    Docuccino::extend(new class($inside, $outside) implements OperationExtension
    {
        public function __construct(
            private readonly string $inside,
            private readonly string $outside,
        ) {}

        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            if ($context->route->uri !== '/api/ping') {
                return;
            }

            throw new RuntimeException(sprintf(
                'file_get_contents(%s): Failed to open stream, called in %s on line 10',
                $this->outside,
                $this->inside,
            ));
        }
    });

    $failed = array_values(array_filter(
        diagnosticsCoded(generateDocument()->diagnostics, 'route.build-failed'),
        static fn (Diagnostic $d): bool => $d->routeSignature === 'GET /api/ping',
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->message)
        // The route signature is our own words and survives whole — it is absolute-looking too, and
        // losing it would cost the reader the one thing naming where to go.
        ->toContain('GET /api/ping')
        ->toContain('Failed to open stream')
        ->toContain('app/Http/Controllers/FormController.php')
        ->toContain('Reader.php')
        ->not->toContain(base_path())
        ->and($failed[0]->message)->not->toContain($outside);
});

it('publishes no machine path in an overlay warning', function (): void {
    // Both halves are machine paths: the glob resolved the file, and the YAML parser names it again in
    // what it threw.
    $dir = sys_get_temp_dir().'/docuccino-overlay-portability-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/broken.yaml', "overlay: 1.0.0\nactions: [ { target: '\$' }\n");
    config()->set('docuccino.documents.default.overlays', [$dir.'/*.yaml']);

    $warnings = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'overlay.invalid',
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('broken.yaml')
        ->and($warnings[0]->message)->not->toContain($dir);

    array_map(unlink(...), glob($dir.'/*') ?: []);
    @rmdir($dir);
});

it('publishes no machine path in what the analyser reported', function (): void {
    // The other crossing: a failed analysis quotes whatever the underlying tool threw, and that travels
    // on the fragment — so it has to be scrubbed on the way in, not on the way out, or the cache keeps
    // a copy of the machine path too.
    $symbol = ApiReportController::class.'::index';
    $broken = base_path('app/Support/Broken.php');

    $engine = new StubTypeEngine([$symbol => new ActionAnalysis(diagnostics: [new Diagnostic(
        severity: Severity::Warning,
        code: 'inference.action-failed',
        message: sprintf('Type analysis of %s failed: internal error in %s', $symbol, $broken),
    )])]);

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-analysed', [ApiReportController::class, 'index']),
        static fn (): TypeEngine => $engine,
    );

    $reports = diagnosticsCoded($result->diagnostics, 'inference.action-failed');

    expect($reports)->toHaveCount(1)
        // The class name it names is a namespace, not a path, and comes through untouched.
        ->and($reports[0]->message)->toContain($symbol)
        ->and($reports[0]->message)->toContain('app/Support/Broken.php')
        ->and($reports[0]->message)->not->toContain($broken);
});

it('reports a failed route on a warm cache hit exactly as a cold one does', function (): void {
    // A failed route writes no fragment, so its diagnostic is re-raised rather than replayed — which
    // makes the scrub part of every build rather than of the first one. The claim is worth pinning
    // either way: a warm build that reported the failure differently, or not at all, would be the
    // silent degradation the cache is not allowed to introduce.
    $doomedSymbol = AdminReportController::class.'::index';
    $missing = base_path('storage/app/missing.json');

    $engine = static fn (): TypeEngine => new ThrowingTypeEngine(
        WorkbenchEngine::make(),
        $doomedSymbol,
        sprintf('file_get_contents(%s): Failed to open stream: No such file or directory', $missing),
    );

    $healthy = static function (Router $router): void {
        $router->get('api/zz-healthy', [ApiReportController::class, 'index']);
    };

    $warm = assertWarmEqualsCold($healthy, static function (Router $router) use ($healthy): void {
        $healthy($router);
        $router->get('api/zz-doomed', [AdminReportController::class, 'index']);
    }, $engine);

    $failed = diagnosticsCoded($warm->diagnostics, 'route.build-failed');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->routeSignature)->toBe('GET /api/zz-doomed')
        ->and($failed[0]->message)->toContain('storage/app/missing.json')
        ->and($failed[0]->message)->not->toContain($missing);
});

it('publishes no machine path in the summary of what an exception handler could not fold', function (): void {
    // A genuine closure render callback has no class to name, so the label the tier keys its deferrals by
    // is the closure's FILE — absolute, straight from reflection. Recorded here rather than provoked,
    // because what is under test is the crossing into the diagnostic, not what makes a body unfoldable.
    //
    // Recorded as the ROUTE NOTE the tier records, which is the label's whole journey now: it rides the
    // route's cached fragment and is drained into the summary's log from there, so the scrub has to be
    // where the message is composed and cannot be at the tier. Seeding the log instead would prove
    // nothing — the pipeline empties it before the first route.
    bindStubEngine();
    Docuccino::extend(new RouteNoteRecorder(HandlerDeferralLog::CHANNEL, base_path('bootstrap/app.php').'::closure@42', 'RuntimeException'));

    $summaries = diagnosticsCoded(generateDocument()->diagnostics, 'inferred-handler.too-dynamic');

    expect($summaries)->toHaveCount(1)
        // The locator still names the file and the line — that is the half the author needs.
        ->and($summaries[0]->message)->toContain('bootstrap/app.php::closure@42')
        ->and($summaries[0]->message)->not->toContain(base_path());
});

it('publishes no machine path in that summary on a WARM build either', function (): void {
    // The label now reaches the diagnostic through a cached fragment, and a fragment is JSON on disk that
    // legitimately holds the unscrubbed identity — two closures degrading to one basename must not merge.
    // So the scrub is downstream of the replay, and this is the row that says the replay does not route
    // around it: same bytes warm as cold, and the machine named in neither.
    $routes = static function (Router $router): void {
        $router->get('api/zz-noted', [ApiReportController::class, 'index']);
    };
    Docuccino::extend(new RouteNoteRecorder(HandlerDeferralLog::CHANNEL, base_path('bootstrap/app.php').'::closure@42', 'RuntimeException'));

    $warm = assertWarmEqualsCold($routes, $routes, static fn (): TypeEngine => WorkbenchEngine::make());
    $summaries = diagnosticsCoded($warm->diagnostics, 'inferred-handler.too-dynamic');

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->message)->toContain('bootstrap/app.php::closure@42')
        ->and($summaries[0]->message)->not->toContain(base_path());
});

it('publishes no machine path in the render callback it had to skip', function (): void {
    // The second channel, and the pre-existing one: the reflector labels a skipped anonymous closure
    // `closure@<file>:<line>`, and the file is this test's own — outside the workbench base path, so the
    // ladder relativises it against the package root instead.
    bindStubEngine();
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    // A builtin-typed first parameter is one of the reflector's three skip reasons.
    $handler->renderable(static fn (string $whoops) => response()->json([], 400));

    $ours = array_values(array_filter(
        diagnosticsCoded(generateDocument()->diagnostics, 'inferred-handler.render-callback-skipped'),
        static fn (Diagnostic $d): bool => str_contains($d->message, basename(__FILE__)),
    ));

    expect($ours)->toHaveCount(1)
        ->and($ours[0]->message)->toContain('tests/Feature/'.basename(__FILE__))
        ->and($ours[0]->message)->not->toContain(dirname(__DIR__, 4));
});

it('leaves a callback label that names no file exactly as it stands', function (string $label): void {
    // The negative path. A label is our own words, and most of them are a class and a method: an FQCN's
    // separators, a relative path's directories and a bare `::method` are not machine paths and must
    // survive whole, or the scrub costs the reader the name it was meant to protect.
    bindStubEngine();
    Docuccino::extend(new RouteNoteRecorder(HandlerDeferralLog::CHANNEL, $label, 'RuntimeException'));

    $summaries = diagnosticsCoded(generateDocument()->diagnostics, 'inferred-handler.too-dynamic');

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->message)->toContain($label);
})->with([
    'a namespaced class and method' => 'App\\Exceptions\\Renderer::__invoke',
    'a path already relative' => 'bootstrap/app.php::closure@42',
    'a single-segment file' => 'app.php::closure@42',
]);

it('publishes no machine path in any diagnostic a whole build raises', function (): void {
    // The standing guard behind the rows above: whatever the workbench build has to say, it says it
    // without naming the machine that said it, so two checkouts emit the same bytes.
    bindStubEngine();
    $repositoryRoot = dirname(__DIR__, 4);
    $diagnostics = generateDocument()->diagnostics;

    // A build with nothing to say would satisfy the loop below without proving anything.
    expect($diagnostics)->not->toBeEmpty();

    foreach ($diagnostics as $diagnostic) {
        expect($diagnostic->message)->not->toContain(base_path())
            ->and($diagnostic->message)->not->toContain($repositoryRoot)
            ->and($diagnostic->help ?? '')->not->toContain($repositoryRoot)
            ->and($diagnostic->source?->file ?? '')->not->toStartWith('/');
    }
});
