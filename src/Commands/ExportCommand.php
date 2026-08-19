<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\Directory;
use Docuccino\Laravel\Config\ExportDiagnostics;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and writes each of its configured export targets. Diagnostics
 * print grouped by route; the exit code honours `--fail-on`.
 *
 * One build, many artifacts: analysis is the expensive half, so emitting three formats costs one
 * analysis and three emits rather than three runs of everything.
 *
 * `--format`/`--out` REPLACE the configured target list for the run rather than filtering it — asking
 * for a 3.0 file must produce one whether or not a 3.0 target happens to be configured.
 */
final class ExportCommand extends Command
{
    use FailsOnSeverity;
    use GuardsEnabled;
    use IteratesDocuments;
    use RendersDiagnostics;
    use StringOptions;

    protected $signature = 'docuccino:export
        {document? : The configured document key (defaults to every document)}
        {--format= : uir | openapi-3.2 | openapi-3.1 | openapi-3.0 | postman — writes this one format instead of the configured targets}
        {--out= : Output path (defaults to the matching target, else the document export path)}
        {--fail-on=none : none | error | warning | info | hint — the quietest severity that still makes the command exit non-zero}
        {--provenance=winners : none | winners | full — UIR provenance detail}
        {--drop-ids : Omit the flat x-docuccino-id member OpenAPI output carries by default (the artifact then diffs by method + path)}
        {--yaml : Emit YAML instead of JSON}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Generate and export API documentation from your routes.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        if (! $this->validateOptions($builder) || ! $this->validateTargets($builder)) {
            return self::FAILURE;
        }

        $exit = $this->forEachDocument($builder, function (string $key) use ($builder, $engine): int {
            $result = $builder->build($key, $engine);
            $diagnostics = $this->withAcceptanceNotes($result->diagnostics);

            $written = $this->writeTargets($builder->config($key), $result->document);
            $this->renderDiagnostics($key, $diagnostics);

            return $written && ! $this->failsOnAny($diagnostics) ? self::SUCCESS : self::FAILURE;
        });

        return $this->reportStaleAcceptances($exit);
    }

    /**
     * CLI-input problems: the user's own typing, so plain messages rather than diagnostic codes.
     * Every one of them errors out rather than coercing — an option that quietly means something else
     * than what was typed ships the wrong artifact, or none of the gate that was asked for.
     */
    private function validateOptions(DocumentBuilder $builder): bool
    {
        if (! $this->validateFailOn()) {
            return false;
        }

        // A typo errors out rather than falling back to OpenAPI 3.2 and shipping the wrong artifact.
        $format = $this->stringOption('format');
        if ($format !== null && ! Formats::supports($format)) {
            $this->error(sprintf('Unknown --format "%s"; expected one of: %s.', $format, implode(', ', Formats::ids())));

            return false;
        }

        if ($format !== null && $this->option('yaml') === true && ! Formats::serialisesYaml($format)) {
            $this->error(sprintf('--yaml cannot be used with --format=%s, which has no YAML serialisation.', $format));

            return false;
        }

        $provenance = $this->stringOption('provenance');
        if ($provenance !== null && ProvenanceLevel::tryFrom($provenance) === null) {
            $this->error(sprintf(
                'Unknown --provenance "%s"; expected one of: %s.',
                $provenance,
                implode(', ', array_map(static fn (ProvenanceLevel $level): string => $level->value, ProvenanceLevel::cases())),
            ));

            return false;
        }

        return $this->validateOut($builder);
    }

    /** The two ways one `--out` path would have to hold several artifacts at once. */
    private function validateOut(DocumentBuilder $builder): bool
    {
        if ($this->stringOption('out') === null) {
            return true;
        }

        $only = $this->argument('document');

        // Several documents — later ones would clobber earlier ones.
        if (! is_string($only) && count($builder->documentKeys()) > 1) {
            $this->error('--out cannot be used when exporting multiple documents; pass a document argument or configure per-document export.path.');

            return false;
        }

        // Several formats: without --format the run writes EVERY configured target, so each emit would
        // land on the same path and only the last format would survive it.
        if ($this->stringOption('format') !== null) {
            return true;
        }

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $targets = $builder->config($key)->exportTargets();
            if (count($targets) > 1) {
                $this->error(sprintf(
                    '--out needs --format: documents.%s configures %d export targets (%s), and one path cannot hold them all — only the last format written would survive. Pass --format to pick one, or drop --out to write each target to its configured path.',
                    $key,
                    count($targets),
                    implode(', ', array_map(static fn (ExportTarget $target): string => $target->format, $targets)),
                ));

                return false;
            }
        }

        return true;
    }

    /**
     * Config problems, checked across EVERY document before the first build: a broken target list means
     * nothing sensible can be written, and finding that out after a full analysis wastes the expensive
     * half of the run.
     */
    private function validateTargets(DocumentBuilder $builder): bool
    {
        $only = $this->argument('document');
        $fatal = false;
        /** @var array<string, string> $claimed */
        $claimed = [];

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $config = $builder->config($key);
            $diagnostics = ExportDiagnostics::for($config);

            foreach ($this->targets($config) as $target) {
                $absolute = Paths::absolute($target->path, base_path());
                $owner = $claimed[$absolute] ?? null;

                if ($owner !== null && $owner !== $key) {
                    $diagnostics[] = new Diagnostic(
                        severity: Severity::Error,
                        code: 'config.export-path-collision',
                        message: sprintf("documents.%s writes '%s', which document '%s' already writes — one of them would clobber the other.", $key, $target->path, $owner),
                    );
                }

                $claimed[$absolute] = $owner ?? $key;
            }

            if ($diagnostics !== []) {
                $this->renderDiagnostics($key, $diagnostics);
                $fatal = $fatal || ExportDiagnostics::fatal($diagnostics);
            }
        }

        return ! $fatal;
    }

    /**
     * The run's targets: the CLI override when one is given, else what the document configured.
     *
     * The override's path is looked up BY FORMAT rather than by position, so which file
     * `--format=openapi-3.1` lands in never depends on how the target list happens to be ordered.
     *
     * @return list<ExportTarget>
     */
    private function targets(DocumentConfig $config): array
    {
        $format = $this->stringOption('format');
        if ($format === null) {
            return $config->exportTargets();
        }

        $out = $this->stringOption('out');
        if ($out !== null) {
            return [new ExportTarget($format, $out)];
        }

        foreach ($config->exportTargets() as $target) {
            if ($target->format === $format) {
                return [$target];
            }
        }

        return [new ExportTarget($format, $config->exportPath())];
    }

    /** Writes every target for one document, one at a time. False when any write failed. */
    private function writeTargets(DocumentConfig $config, UirDocument $document): bool
    {
        $ok = true;

        foreach ($this->targets($config) as $target) {
            $ok = $this->write($target, $document, $config) && $ok;
        }

        return $ok;
    }

    private function write(ExportTarget $target, UirDocument $document, DocumentConfig $config): bool
    {
        $result = Formats::emit($target->format, $document, $this->emitOptions($target, $config));

        $path = Paths::absolute($this->stringOption('out') ?? $target->path, base_path());
        $directory = dirname($path);
        if (! Directory::ensure($directory)) {
            $this->error(sprintf('Could not create %s.', $directory));

            return false;
        }

        // Atomic for the reason {@see AtomicFile} gives: `docuccino:watch` re-exports on every save.
        if (! AtomicFile::write($path, $result->output)) {
            $this->error(sprintf('Could not write %s.', $path));

            return false;
        }

        $this->info(sprintf('Wrote %s (%s).', $path, $target->format));

        // A downlevel drops or approximates things; say so rather than shipping a quieter contract.
        $this->renderDiagnostics($target->format, $result->report->diagnostics);

        return true;
    }

    private function emitOptions(ExportTarget $target, DocumentConfig $config): EmitOptions
    {
        // `--yaml` is the single-target override's say; a configured target states it in its own path.
        $yaml = $this->option('yaml') === true || $target->yaml();

        return (new EmitOptions)
            ->withYaml($yaml && Formats::serialisesYaml($target->format))
            ->withProvenance($this->provenanceLevel())
            ->withKeepIds($this->option('drop-ids') !== true)
            ->withMockFakerKey($config->mockFakerKey());
    }

    private function provenanceLevel(): ProvenanceLevel
    {
        // Validated up front, so the fallback is only ever the unset flag's default.
        return ProvenanceLevel::tryFrom($this->stringOption('provenance') ?? '') ?? ProvenanceLevel::Winners;
    }
}
