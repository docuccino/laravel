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
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Support\Paths;
use Symfony\Component\Yaml\Yaml;

/**
 * The single entry point every command and the runtime viewer share for turning
 * `config('docuccino.documents.*')` into a built {@see GenerationResult}: it resolves the
 * {@see DocumentConfig}, loads the document's Overlay 1.0 files (a malformed overlay becomes a
 * warning diagnostic rather than a fatal), merges the config extensions, and runs the pipeline.
 * Centralising this keeps export/validate/diff/cache/viewer on one identical build path.
 *
 * Responsibility split: this is the config-facade — it turns configuration + files into inputs and
 * delegates the actual route→operation→assemble→validate pipeline to {@see DocumentGenerator}.
 *
 * @internal
 */
final class DocumentBuilder
{
    public function __construct(
        private readonly DocumentConfigFactory $configs,
        private readonly DocumentGenerator $generator,
        private readonly string $basePath,
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

    /**
     * Build one document. Overlay-parse warnings are folded into the returned diagnostics so a
     * caller sees them alongside the pipeline's own.
     */
    public function build(string $key, TypeEngine $engine): GenerationResult
    {
        $config = $this->config($key);
        [$overlays, $overlayDiagnostics] = $this->overlays($config);
        $preDiagnostics = [...$this->engineModeDiagnostics(), ...$overlayDiagnostics];

        $result = $this->generator->generate($config, $engine, $this->configExtensions(), $overlays);

        if ($preDiagnostics === []) {
            return $result;
        }

        return new GenerationResult($result->document, $this->sort([...$preDiagnostics, ...$result->diagnostics]));
    }

    /**
     * Warn when a not-yet-wired engine mode is selected. The orchestrated and caching compositions
     * exist in the inference engine but are not plumbed through {@see TypeEngineFactory} yet, so a
     * build silently runs in-process — surface that rather than let it pass unnoticed.
     *
     * @return list<Diagnostic>
     */
    private function engineModeDiagnostics(): array
    {
        $mode = config('docuccino.engine.mode');

        if ($mode === TypeEngineMode::Orchestrated->value || $mode === TypeEngineMode::Caching->value) {
            return [new Diagnostic(
                severity: Severity::Warning,
                code: 'engine.mode-not-wired',
                message: sprintf('Engine mode "%s" is not yet wired; inference ran in-process.', $mode),
                help: 'The orchestrated and caching engine modes arrive in a later phase; set DOCUCCINO_ENGINE=in-process to silence this.',
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
                } catch (InvalidOverlayException $exception) {
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
