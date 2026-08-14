<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\InvalidOverlayException;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\TypeEngineMode;
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
        $preDiagnostics = [...$this->engineDiagnostics(), ...$overlayDiagnostics];

        $result = $this->generator->generate($config, $engine, $this->configExtensions(), $overlays);

        if ($preDiagnostics === []) {
            return $result;
        }

        return new GenerationResult($result->document, $this->sort([...$preDiagnostics, ...$result->diagnostics]));
    }

    /**
     * The build's one report on the state of inference, emitted once per document.
     *
     * A missing engine package is a WARNING, not info: the configured mode asked for inference and the
     * document quietly lost a whole tier of facts (recovered types, response shapes, thrown errors).
     * `mode: null` is an explicit opt-out, so it says nothing. An unrecognised mode — a typo, or one a
     * later version dropped — ran in-process instead of failing the build, which is worth saying out
     * loud; it is suppressed when the engine is absent, since which mode was asked for is then moot.
     *
     * @return list<Diagnostic>
     */
    private function engineDiagnostics(): array
    {
        $mode = config('docuccino.engine.mode');

        if ($mode !== TypeEngineMode::Null->value && ! $this->engine->installed()) {
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

        if (is_string($mode) && $mode !== '' && TypeEngineMode::tryFrom($mode) === null) {
            return [new Diagnostic(
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
            )];
        }

        return [];
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
     * @return list<class-string|object>
     */
    private function configExtensions(): array
    {
        $out = [];
        foreach ((array) config('docuccino.extensions', []) as $extension) {
            if (is_object($extension)) {
                $out[] = $extension;
            } elseif (is_string($extension) && class_exists($extension)) {
                $out[] = $extension;
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<OverlayDocument>, 1: list<Diagnostic>}
     */
    private function overlays(DocumentConfig $config): array
    {
        $overlays = [];
        $diagnostics = [];

        foreach ($config->overlays as $pattern) {
            foreach (glob(Paths::absolute($pattern, $this->basePath)) ?: [] as $file) {
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
                        message: sprintf('Skipped overlay %s: %s', $file, $exception->getMessage()),
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
