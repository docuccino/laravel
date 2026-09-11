<?php

declare(strict_types=1);

use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentEmitOptions;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Support\Facades\Artisan;

/**
 * Which entry points can be asked whether a document is sound, and what each of them answers.
 *
 * Two checks divide that question, and they used to leave a gap between them. The UIR document is
 * held to its own schema on every build, so any command that builds reports `document.schema-invalid`.
 * The artifact a consumer actually receives is held to the published OpenAPI schema for the version it
 * claims only where something EMITS one — which for a long time meant `docuccino:export` and a viewer
 * request, and not the command whose whole job is to answer the question.
 *
 * So this is the union asserted against the domain rather than two subsets sitting side by side, and
 * a member that owes no answer carries a ROW saying so instead of falling in the gap between them.
 *
 * The rows are claims about behaviour and the guards under them are what stops the table being a
 * comment: the command set is read out of Artisan rather than typed here, and every place in the
 * adapter that emits an artifact is read out of the source. Both scans assert a plausible minimum, so
 * one that stops seeing its shapes fails rather than passing over an empty set forever.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    $this->overlayDir = sys_get_temp_dir().'/docuccino-soundness-'.uniqid();
    mkdir($this->overlayDir);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->overlayDir.'/*') ?: []);
    @rmdir($this->overlayDir);
});

/**
 * Every entry point that builds a document, and what each says about the artifact behind it.
 *
 * `emits`      — produces the bytes a consumer receives, so the artifact check can run at all.
 * `reaches`    — an invalid artifact reaches the person who ran it, on the channel they are watching.
 * `fails`      — an invalid artifact makes the run non-zero, below anything `--fail-on` can reach.
 * `runnable`   — the arguments this suite drives it with, or null plus the reason it cannot.
 *
 * @return array<string, array{emits: bool, reaches: bool, fails: bool, runnable: ?array<string, string>, why: string}>
 */
function artifactSoundnessTable(): array
{
    return [
        'docuccino:export' => [
            'emits' => true, 'reaches' => true, 'fails' => true,
            'runnable' => ['--format' => 'openapi-3.2', '--out' => '@tmp'],
            'why' => 'Writes every configured target and reports what each emitter said while writing it.',
        ],
        'docuccino:validate' => [
            'emits' => true, 'reaches' => true, 'fails' => true,
            'runnable' => [],
            'why' => 'Emits every configured target in memory purely to check it; writes nothing.',
        ],
        'docuccino:cache' => [
            'emits' => true, 'reaches' => true, 'fails' => true,
            'runnable' => [],
            'why' => 'Warms the payload the viewer is served, so it owes the operator what the emitter said about it.',
        ],
        'docuccino:watch' => [
            'emits' => true, 'reaches' => true, 'fails' => false,
            'runnable' => null,
            'why' => 'Drives docuccino:export in a fresh process each rebuild and prints its output; the loop '
                .'itself keeps running, because a watcher that exited on a broken build would stop watching '
                .'for the fix. Not driven here: it does not return.',
        ],
        'docuccino:diff' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Compares two documents. Nothing is emitted, and a diff of two artifacts that are both '
                .'out of spec is still a correct diff. Not driven here: it needs a committed artifact.',
        ],
        'docuccino:coverage' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Reports which documented responses a test suite exercised. Emits nothing. Not driven '
                .'here: with no recorded logs it refuses for its own reason, so its exit code would say '
                .'nothing about the artifact either way.',
        ],
        'docuccino:explain' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => ['route' => 'POST /api/tickets', 'document' => 'default'],
            'why' => 'Explains one operation from the built document. Emits nothing, so the artifact it '
                .'never produced cannot be reported on — the negative half of this table.',
        ],
        'docuccino:version-changes' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Scaffolds changelog entries from a diff. Emits nothing. Not driven here: it writes '
                .'content files.',
        ],
        'docuccino:clear' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => [],
            'why' => 'Empties the runtime cache. Builds nothing and emits nothing.',
        ],
        'docuccino:install' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Writes the two config files. Builds nothing. Not driven here: it writes into the project.',
        ],
        'docuccino:migrate-config' => [
            'emits' => false, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Moves build settings out of the PHP config file. Builds nothing. Not driven here: it '
                .'rewrites config files.',
        ],
        'viewer request' => [
            'emits' => true, 'reaches' => true, 'fails' => false,
            'runnable' => null,
            'why' => 'Serves the page. The reader is an API consumer who cannot act on our defect, so the '
                .'report goes to the log for the author instead, and the page still renders — a viewer that '
                .'500s on a spec finding helps nobody. Its log level is proved in EmittedSpecCheckTest.',
        ],
        'Testing\ContractBuild' => [
            'emits' => true, 'reaches' => false, 'fails' => false,
            'runnable' => null,
            'why' => 'Re-emits to answer whether the COMMITTED artifact is current, which is a different '
                .'question: a committed copy of an out-of-spec artifact is still up to date. '
                .'assertDocumentUpToDate() would have to change what its name promises to answer the other '
                .'one, so docuccino:validate is where that lives.',
        ],
    ];
}

/** The adapter source files that emit an artifact, and the table rows each of them serves. */
function artifactEmittingSources(): array
{
    return [
        'Commands/ExportCommand.php' => ['docuccino:export'],
        'Commands/ValidateCommand.php' => ['docuccino:validate'],
        'Testing/ContractBuild.php' => ['Testing\ContractBuild'],
        // One emission seam behind two members: the request logs it, the command prints it.
        'Viewer/ViewerDrivers.php' => ['viewer request', 'docuccino:cache'],
    ];
}

/** An overlay hanging a `$ref` on the document that names a component nothing defines. */
function soundnessDanglingRef(string $dir): void
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

/**
 * The denominator. A table listing eleven of twelve commands would pass every row it holds and say
 * nothing about the one it forgot, so the command set is read from the registrar the provider
 * actually populated.
 */
it('holds a row for every registered command', function (): void {
    $registered = array_values(array_filter(
        array_keys(Artisan::all()),
        static fn (string $name): bool => str_starts_with($name, 'docuccino:'),
    ));

    sort($registered);

    $tabled = array_values(array_filter(
        array_keys(artifactSoundnessTable()),
        static fn (string $name): bool => str_starts_with($name, 'docuccino:'),
    ));

    sort($tabled);

    // Anti-vacuity: a scan that matched nothing would agree with an empty table.
    expect(count($registered))->toBeGreaterThanOrEqual(11)
        ->and($tabled)->toBe($registered);
});

/**
 * The other denominator, and the one that would notice a new seam. A file that emits an artifact and
 * appears in no row is a member of this domain nobody wrote an answer for — which is exactly how the
 * command whose job is to answer the question came to emit nothing at all.
 */
it('holds a row for every place the adapter emits an artifact', function (): void {
    $root = dirname(__DIR__, 2).'/src';
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/Formats::emit\(|->emitWithReport\(/', $source) === 1) {
            $found[] = str_replace($root.'/', '', $file->getPathname());
        }
    }

    sort($found);
    $expected = array_keys(artifactEmittingSources());
    sort($expected);

    // Anti-vacuity: a scan that stopped recognising the call shapes would find none of them and
    // agree with an empty expectation.
    expect(count($found))->toBeGreaterThanOrEqual(4)
        ->and($found)->toBe($expected);

    foreach (artifactEmittingSources() as $file => $members) {
        foreach ($members as $member) {
            expect(artifactSoundnessTable())->toHaveKey($member)
                ->and(artifactSoundnessTable()[$member]['emits'])->toBeTrue("$file serves $member");
        }
    }
});

/** A row claiming an invalid artifact reaches nobody cannot also claim it fails the run. */
it('states a reachable finding wherever it states a failing one', function (string $member, array $row): void {
    expect($row['fails'] && ! $row['reaches'])->toBeFalse()
        ->and($row['reaches'] && ! $row['emits'])->toBeFalse()
        ->and(trim($row['why']))->not->toBe('', "$member owes a reason");
})->with(fn (): array => array_map(
    static fn (string $key): array => [$key, artifactSoundnessTable()[$key]],
    array_keys(artifactSoundnessTable()),
));

/**
 * The table executed. Every row this suite can drive is run against a document whose artifact really
 * is out of spec, and the row's two claims are read off the run rather than off the comment beside
 * it — including the rows that claim to say nothing, which is where the gap lived.
 */
it('answers about an invalid artifact exactly as its row says', function (string $member): void {
    soundnessDanglingRef($this->overlayDir);

    $row = artifactSoundnessTable()[$member];
    $out = sys_get_temp_dir().'/docuccino-soundness-'.uniqid().'.json';

    $args = array_map(
        static fn (string $value): string => $value === '@tmp' ? $out : $value,
        $row['runnable'] ?? [],
    );

    $run = $this->artisan($member, $args);

    $row['reaches']
        ? $run->expectsOutputToContain('document.openapi-invalid')
        : $run->doesntExpectOutputToContain('document.openapi-invalid');

    $row['fails'] ? $run->assertFailed() : $run->assertSuccessful();

    $run->run();

    @unlink($out);
})->with(fn (): array => array_values(array_filter(
    array_keys(artifactSoundnessTable()),
    static fn (string $key): bool => artifactSoundnessTable()[$key]['runnable'] !== null,
)));

/**
 * The half of the `docuccino:cache` row an exit code cannot show. It fails, and it caches anyway —
 * withholding the payload would leave the viewer with nothing behind it, where the whole point of
 * reporting rather than refusing is that a partial answer beats none. The export writes its file for
 * the same reason, and that half is executed in EmittedSpecCheckTest.
 */
it('still caches the payload it exited non-zero for', function (): void {
    soundnessDanglingRef($this->overlayDir);

    $this->artisan('docuccino:cache')
        ->expectsOutputToContain('document.openapi-invalid')
        ->assertFailed();

    expect(app(DocumentCache::class)->get('default', 'openapi-3.2'))
        ->toBeString()
        ->toContain('NobodyDefinesThis');
});

/**
 * And the second half of the union, in the other direction: the UIR document's own schema. Every
 * command that BUILDS reports a violation of it, and `docuccino:validate` is the one that fails on it
 * below the reach of `--fail-on` — which is the asymmetry that makes the two halves worth stating
 * together rather than assuming one implies the other.
 */
it('fails only on docuccino:validate for a UIR document that fails its own schema', function (): void {
    // `x-docuccino` is UIR's own extension member and never survives OpenAPI emission, so a member the
    // UIR schema refuses there is invisible to every artifact check — the one shape that separates the
    // two halves cleanly.
    file_put_contents($this->overlayDir.'/uir.yaml', <<<'YAML'
        overlay: 1.0.0
        info:
          title: Bad UIR
          version: 1.0.0
        actions:
          - target: $.x-docuccino
            update:
              bogusMember: 1
        YAML);
    setBuild('documents.default.overlays', [$this->overlayDir.'/*.yaml']);

    $out = sys_get_temp_dir().'/docuccino-soundness-'.uniqid().'.json';

    $this->artisan('docuccino:validate')
        ->expectsOutputToContain('document.schema-invalid')
        ->assertFailed();

    // Reported by the export too — printed, and left to `--fail-on` rather than fatal, because the
    // artifact it wrote is a valid document of its own format and shipping it loses the consumer
    // nothing.
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out])
        ->expectsOutputToContain('document.schema-invalid')
        ->assertSuccessful();

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out, '--fail-on' => 'error'])
        ->assertFailed();

    @unlink($out);
});

/**
 * A check over bytes nobody ships answers about nothing, so the bytes `docuccino:validate` holds to a
 * schema have to be the ones `docuccino:export` writes with no flags. One owner states them
 * ({@see DocumentEmitOptions::canonical()}) and this executes the agreement, because provenance
 * detail, the flat id member and the carrier are each a way for two readers of one config to produce
 * different files while both look right.
 *
 * The YAML row is the load-bearing one: the check reads the carrier, and a YAML target checked as
 * JSON would hold the wrong bytes to the schema while reporting a clean run.
 */
it('holds the same bytes a bare export writes, for every carrier a target can have', function (string $format, string $suffix): void {
    $path = sys_get_temp_dir().'/docuccino-canonical-'.uniqid().$suffix;
    setBuild('documents.default.export', ['targets' => [['format' => $format, 'path' => $path]]]);

    $this->artisan('docuccino:export')->assertSuccessful()->run();

    $builder = app(DocumentBuilder::class);
    $config = $builder->config('default');
    $document = $builder->build('default', WorkbenchEngine::make())->document;

    $written = (string) file_get_contents($path);

    expect($written)->not->toBe('')
        // Anti-vacuity for the carrier: a YAML row holding JSON would match a JSON re-emission and
        // prove the opposite of what it is here for.
        ->and(str_starts_with($written, '{'))->toBe($suffix === '.json')
        ->and($written)->toBe(Formats::emit(
            $format,
            $document,
            DocumentEmitOptions::canonical($config, $config->exportTargets()[0]),
        )->output);

    @unlink($path);
})->with([
    'openapi-3.2 as JSON' => ['openapi-3.2', '.json'],
    'openapi-3.2 as YAML' => ['openapi-3.2', '.yaml'],
    'openapi-3.0 as JSON' => ['openapi-3.0', '.json'],
    'uir as JSON' => ['uir', '.json'],
]);

/**
 * The line every target earns, said on the quiet path too. A reader with no way to tell the artifact
 * half ran is where this whole question started, and a format with no published schema behind it says
 * that rather than staying silent — silence beside a checked target reads as the clean answer.
 */
it('says which artifact it checked, and says when it could not', function (): void {
    setBuild('documents.default.export', ['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        ['format' => 'uir', 'path' => 'docs/uir.json'],
        ['format' => 'postman', 'path' => 'docs/postman.json'],
    ]]);

    $this->artisan('docuccino:validate')
        ->expectsOutputToContain('default: openapi-3.2 artifact valid against its published schema.')
        ->expectsOutputToContain('default: uir has no published schema to hold an artifact to; not checked.')
        ->expectsOutputToContain('default: postman has no published schema to hold an artifact to; not checked.')
        ->assertSuccessful();
});

/**
 * What widening this command does to a reader's exit code, measured at every floor the flag takes.
 * The shipped configuration writes one OpenAPI 3.2 artifact, which loses nothing on the way out and
 * answers to its own schema, so an out-of-the-box pipeline has to stay exactly where it was — down to
 * `--fail-on=hint`, the strictest value there is.
 *
 * Narrowed to `api/checkout`, whose build is silent, so what a run reports is the artifact channel
 * and nothing else and a green row really means the floor stayed quiet.
 */
it('stays green at every floor for the artifact the shipped configuration writes', function (string $failOn): void {
    setBuild('documents.default.routes.include', ['api/checkout']);

    $this->artisan('docuccino:validate', ['--fail-on' => $failOn])->assertSuccessful();
})->with(['none', 'error', 'warning', 'info', 'hint']);

/**
 * And where it does move. A downlevel target's losses are reported by this command now, so they reach
 * the floor exactly as they reach the export's — printing and gating being one act. They sit at
 * `info`, so a pipeline gating at `warning` is untouched and one gating at `info` is told what the
 * older target costs before anything is written.
 */
it('gates on what a downlevel target loses, at the floor that reaches it', function (): void {
    setBuild('documents.default.routes.include', ['api/checkout']);
    setBuild('documents.default.export', ['targets' => [['format' => 'openapi-3.0', 'path' => 'docs/openapi-3.0.json']]]);

    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])
        ->expectsOutputToContain('downlevel.const')
        ->assertFailed();

    $this->artisan('docuccino:validate', ['--fail-on' => 'warning'])
        ->expectsOutputToContain('downlevel.const')
        ->assertSuccessful();

    // Nothing was written for any of it: the check emits and throws the bytes away.
    expect(is_file(base_path('docs/openapi-3.0.json')))->toBeFalse();
});

/**
 * `diagnostics.accept` carves into the exit code and nothing else, and it has to reach this command's
 * new channel too — a config file that says a code is accepted while the gate fails on it anyway is
 * the lie the accept list exists to prevent.
 */
it('lets diagnostics.accept quiet an artifact report it now gates on', function (): void {
    setBuild('documents.default.routes.include', ['api/checkout']);
    setBuild('documents.default.export', ['targets' => [['format' => 'openapi-3.0', 'path' => 'docs/openapi-3.0.json']]]);

    // Unaccepted first, so the accepted run below carves into a gate that was really closed.
    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])->assertFailed();

    setBuild('diagnostics.accept', ['downlevel.const']);

    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])
        ->expectsOutputToContain('[info, accepted] downlevel.const')
        ->assertSuccessful();
});
