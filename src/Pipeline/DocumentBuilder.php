<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\ReportsBootFailure;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\InvalidOverlayException;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Engine\EngineNeon;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Registry\ConfigExtensions;
use Docuccino\Laravel\Support\Paths;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The one entry point every command and the runtime viewer share for turning
 * `config('docuccino.documents.*')` into a {@see GenerationResult}: resolves the
 * {@see DocumentConfig}, loads Overlay 1.0 files (a malformed one becomes a warning, not a fatal),
 * merges config extensions, and hands off to {@see DocumentGenerator}. Sharing it keeps
 * export/validate/diff/cache/viewer on an identical build path.
 *
 * @internal
 */
final class DocumentBuilder
{
    public function __construct(
        private readonly DocumentConfigFactory $configs,
        private readonly DocumentGenerator $generator,
        private readonly string $basePath,
        private readonly EnginePackage $engine = new EnginePackage,
    ) {}

    /**
     * The configured document keys, in config declaration order.
     *
     * @return list<string>
     */
    public function documentKeys(): array
    {
        return array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys($this->documents()),
        );
    }

    public function hasDocument(string $key): bool
    {
        return is_array($this->documents()[$key] ?? null);
    }

    public function config(string $key): DocumentConfig
    {
        return $this->configs->make($key, Hydrate::map($this->documents()[$key] ?? null), $this->onRouteError());
    }

    /** Overlay-parse warnings are folded in alongside the pipeline's own diagnostics. */
    public function build(string $key, TypeEngine $engine): GenerationResult
    {
        $config = $this->config($key);
        [$overlays, $overlayDiagnostics] = $this->overlays($config);
        [$extensions, $extensionDiagnostics] = ConfigExtensions::read();
        $preDiagnostics = [...$this->engineDiagnostics(), ...$extensionDiagnostics, ...$overlayDiagnostics];

        $result = $this->generator->generate($config, $engine, $extensions, $overlays);

        // The half of the inference report no one can read before the build: the engine boots on the
        // first question a route asks it, and a build that asks none never finds out.
        $diagnostics = [...$preDiagnostics, ...$this->bootFailureDiagnostics($engine)];

        if ($diagnostics === []) {
            return $result;
        }

        return new GenerationResult($result->document, $this->sort([...$diagnostics, ...$result->diagnostics]));
    }

    /**
     * The build's report on the state of inference it can read before generating, emitted once per
     * document ({@see bootFailureDiagnostics()} is the rest of it).
     *
     * A missing engine package is a WARNING, not info: the configured mode asked for inference and the
     * document quietly lost a whole tier of facts (recovered types, response shapes, thrown errors).
     * `mode: null` is an explicit opt-out, so it says nothing. An unrecognised mode — a typo, or one a
     * later version dropped — ran in-process instead of failing the build, which is worth saying out
     * loud; it is suppressed when the engine is absent, since which mode was asked for is then moot.
     * {@see engineNeonDiagnostics()} adds the last of it, and only where something was going to analyse.
     *
     * @return list<Diagnostic>
     */
    private function engineDiagnostics(): array
    {
        $mode = config('docuccino.engine.mode');
        $analysing = $mode !== TypeEngineMode::Null->value;

        if ($analysing && ! $this->engine->installed()) {
            return [new Diagnostic(
                severity: Severity::Warning,
                code: 'engine.not-installed',
                message: 'The inference engine is not installed; documentation came from docblocks and attributes only.',
                help: sprintf(
                    'Install it where you generate: %s. Set DOCUCCINO_ENGINE=null to document without inference and silence this.',
                    EnginePackage::INSTALL_COMMAND,
                ),
            )];
        }

        $diagnostics = [];

        if (is_string($mode) && $mode !== '' && TypeEngineMode::tryFrom($mode) === null) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'engine.mode-unknown',
                message: sprintf('Unknown engine mode "%s"; inference ran in-process.', $mode),
                help: sprintf(
                    'Valid modes are %s. Set DOCUCCINO_ENGINE=in-process to silence this.',
                    implode(', ', array_map(
                        static fn (TypeEngineMode $case): string => '"'.$case->value.'"',
                        TypeEngineMode::cases(),
                    )),
                ),
            );
        }

        return $analysing
            ? [...$diagnostics, ...$this->engineNeonDiagnostics()]
            : $diagnostics;
    }

    /**
     * `engine.neon` names a file that is not there, so the engine analysed without it.
     *
     * A WARNING, like a missing config extension and unlike a boot failure: nothing malfunctioned and
     * the document that got built is true, but the author configured analysis machinery the build
     * could not load, and everything their PHPStan extensions would have sharpened is silently vaguer
     * than they set it up to be. An error would refuse to ship a document that is honest; info would
     * bury a knob that changes every type the engine infers.
     *
     * @return list<Diagnostic>
     */
    private function engineNeonDiagnostics(): array
    {
        /** @var array<string, mixed> $engineConfig */
        $engineConfig = (array) config('docuccino.engine', []);
        $neon = EngineNeon::path($engineConfig, $this->basePath);

        if ($neon === null || is_file($neon)) {
            return [];
        }

        $paths = new RootRelativeSourcePathResolver($this->basePath);

        return [new Diagnostic(
            severity: Severity::Warning,
            code: 'config.engine-neon-missing',
            message: sprintf(
                'engine.neon names %s, which does not exist — inference ran without it, so nothing that file registers shaped this document.',
                $paths->relative($neon),
            ),
            help: 'Check the path in config/docuccino.php; it is read relative to the application base path. Remove the key to analyse with the engine\'s own configuration.',
        )];
    }

    /**
     * The installed engine was asked to analyse and could not start. An ERROR, where an absent engine
     * is only a warning: absence is a shape an install can legitimately have, a boot failure is a
     * malfunction nobody chose, and `--fail-on=error` is how a pipeline refuses to ship a document
     * that quietly lost a whole tier of facts. The analyser's own words arrive with machine paths in
     * them, so they are relativised before ours are composed around them.
     *
     * @return list<Diagnostic>
     */
    private function bootFailureDiagnostics(TypeEngine $engine): array
    {
        $failure = $engine instanceof ReportsBootFailure ? $engine->bootFailure() : null;
        if ($failure === null) {
            return [];
        }

        $messages = new MessagePaths(new RootRelativeSourcePathResolver($this->basePath));

        return [new Diagnostic(
            severity: Severity::Error,
            code: 'engine.boot-failed',
            message: sprintf(
                'The inference engine could not start, so documentation came from docblocks and attributes only: %s',
                $messages->relative($failure),
            ),
            help: 'Generate from the project root in an environment the application boots in — the analyzer boots it the way an artisan command does — and check the engine package and its analyzer are installed at a supported version. Set DOCUCCINO_ENGINE=null to document without inference and silence this.',
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(): array
    {
        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);

        return $documents;
    }

    private function onRouteError(): string
    {
        $configured = config('docuccino.on_route_error');

        return is_string($configured) ? $configured : 'skeleton';
    }

    /**
     * @return array{0: list<OverlayDocument>, 1: list<Diagnostic>}
     */
    private function overlays(DocumentConfig $config): array
    {
        $overlays = [];
        $diagnostics = [];
        // The glob is absolute and the YAML parser names the file it choked on, so both halves of the
        // warning are machine paths until they go through the resolver.
        $paths = new RootRelativeSourcePathResolver($this->basePath);
        $messages = new MessagePaths($paths);

        foreach ($config->overlays as $pattern) {
            $files = glob(Paths::absolute($pattern, $this->basePath)) ?: [];
            // Overlays are applied in order and the later one wins, so the order is part of what they
            // MEAN — and glob(3) sorts by the machine's LC_COLLATE. Byte order, everywhere.
            sort($files, SORT_STRING);

            foreach ($files as $file) {
                try {
                    /** @var array<string, mixed> $parsed */
                    $parsed = (array) Yaml::parseFile($file);
                    $overlays[] = OverlayDocument::fromArray($parsed);
                    // Unparseable YAML and a well-formed file that isn't a valid Overlay 1.0 document
                    // are the same thing to a caller: one skipped overlay, one warning, build carries on.
                } catch (InvalidOverlayException|ParseException $exception) {
                    $diagnostics[] = new Diagnostic(
                        severity: Severity::Warning,
                        code: 'overlay.invalid',
                        message: sprintf(
                            'Skipped overlay %s: %s',
                            $paths->relative($file),
                            $messages->relative($exception->getMessage()),
                        ),
                    );
                }
            }
        }

        return [$overlays, $diagnostics];
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function sort(array $diagnostics): array
    {
        $bag = new DiagnosticCollector;
        $bag->addAll($diagnostics);

        return $bag->sorted();
    }
}
