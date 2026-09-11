<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitReport;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Config\DocumentEmitOptions;
use Docuccino\Laravel\Config\ExportDiagnostics;
use Docuccino\Laravel\Config\UnusableRouteFilterException;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and holds both halves of it to their own schema: the UIR
 * document against the bundled UIR schema, and every artifact the document exports against the
 * published OpenAPI schema for the version that artifact claims.
 *
 * The second half is why this emits at all. A consumer never receives the UIR document — they receive
 * the artifact, and the artifact is where an emitter defect, an overlay-written `$ref` that names
 * nothing, or a duplicate `operationId` shows up. A command whose whole job is to say whether a
 * document is sound has to read the bytes a consumer will read, not only the model behind them.
 *
 * Emitted in memory and thrown away: the check reads bytes rather than a file, so there is nothing to
 * write and no path — temporary or otherwise — for a run to name. Nothing here leaves an artifact
 * behind or changes a byte `docuccino:export` would write.
 */
final class ValidateCommand extends Command
{
    use FailsOnSeverity;
    use GuardsEnabled;
    use IteratesDocuments;
    use RefusesUnreadConfig;
    use RendersDiagnostics;

    protected $signature = 'docuccino:validate
        {document? : The configured document key (defaults to every document)}
        {--fail-on=none : none | error | warning | info | hint — quietest extra severity that also fails (a schema violation always fails)}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Validate the generated document(s), and every artifact they export, against their own schemas.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled() || $this->abortIfConfigUnread() || ! $this->validateFailOn()) {
            return self::FAILURE;
        }

        if (! $this->readableTargets($builder)) {
            return self::FAILURE;
        }

        $exit = $this->forEachDocument($builder, function (string $key) use ($builder, $engine): int {
            $result = $builder->build($key, $engine);
            $diagnostics = $this->withAcceptanceNotes($result->diagnostics);
            $schemaErrors = $this->schemaErrors($diagnostics);

            if ($schemaErrors === []) {
                $this->info(sprintf('%s: valid against UIR %s.', $key, $this->uirVersion($result->document->toArray())));
            } else {
                $this->error(sprintf('%s: %d schema violation(s).', $key, count($schemaErrors)));
            }

            $this->renderDiagnostics($key, $diagnostics);

            $artifactsValid = $this->checkArtifacts($builder->config($key), $result->document);

            return $schemaErrors === [] && $artifactsValid ? self::SUCCESS : self::FAILURE;
        });

        $this->reportStaleAcceptances();

        return $this->withSeverityGate($exit);
    }

    /**
     * Every artifact this document publishes, emitted and held to the published schema for the format
     * it claims. False when any of them is not a valid document of that format.
     *
     * The artifacts are the document's configured export targets rather than a format this command
     * picks: the question is whether what the application SHIPS is sound, and a run that checked 3.2
     * while the pipeline writes 3.0 would answer confidently about an artifact nobody receives. A
     * document configuring no targets still publishes one ({@see DocumentConfig::exportTargets()}), so
     * there is always something to answer about.
     *
     * The report prints like any other, so `--fail-on` reads it and `diagnostics.accept` quiets it.
     * What fails below the floor is an emitter ERROR, on `docuccino:export`'s own terms: a file that
     * is not a valid document of its own format is not a question the reader gets a say over, which is
     * how this command already treats a UIR document that fails its schema.
     */
    private function checkArtifacts(DocumentConfig $config, UirDocument $document): bool
    {
        $valid = true;

        foreach ($config->exportTargets() as $target) {
            $report = Formats::emit(
                $target->format,
                $document,
                DocumentEmitOptions::canonical($config, $target),
            )->report;

            $this->reportArtifact($config->key, $target->format, $report);

            $valid = ! $report->hasError() && $valid;
        }

        return $valid;
    }

    /**
     * One target's verdict, then whatever the emitter said while producing it.
     *
     * A line on the quiet path too, because a reader who cannot tell the artifact half ran is exactly
     * where this command started — and a format nobody can check says SO rather than staying silent,
     * since silence here reads as the clean answer. Only the OpenAPI formats have a published schema
     * to answer to ({@see Formats::checksEmittedArtifact()}); a UIR target answered to its own schema
     * before it was emitted, and a Postman collection has no specification to be held to.
     */
    private function reportArtifact(string $key, string $format, EmitReport $report): void
    {
        $findings = count(array_filter(
            $report->diagnostics,
            static fn (Diagnostic $d): bool => $d->code === 'document.openapi-invalid',
        ));

        if (! Formats::checksEmittedArtifact($format)) {
            $this->line(sprintf('%s: %s has no published schema to hold an artifact to; not checked.', $key, $format));
        } elseif ($findings === 0) {
            $this->info(sprintf('%s: %s artifact valid against its published schema.', $key, $format));
        } else {
            $this->error(sprintf('%s: %s artifact fails its published schema (%d finding(s)).', $key, $format, $findings));
        }

        $this->renderDiagnostics($format, $this->withAcceptanceNotes($report->diagnostics));
    }

    /**
     * The export configuration, read across every selected document before the first build.
     *
     * Two reasons it belongs here rather than only on `docuccino:export`. A target list this cannot
     * read names no artifact to check, so the run would answer about a default target the application
     * never asked for. And a document whose configured artifacts cannot be written is not one to call
     * sound, whichever command is asked. Read before the analysis for the reason the export reads it
     * there: finding it out afterwards wastes the expensive half of the run.
     */
    private function readableTargets(DocumentBuilder $builder): bool
    {
        $only = $this->argument('document');
        $fatal = false;

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            try {
                $diagnostics = ExportDiagnostics::for($builder->config($key));
            } catch (UnusableRouteFilterException $refusal) {
                // Thrown where the filter is resolved, so every later step would meet it first and as
                // a stack trace; rendered here it reads as the config error it is.
                $this->renderDiagnostics($key, [$refusal->diagnostic]);
                $fatal = true;

                continue;
            }

            if ($diagnostics !== []) {
                $this->renderDiagnostics($key, $diagnostics);
                $fatal = ExportDiagnostics::fatal($diagnostics) || $fatal;
            }
        }

        return ! $fatal;
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function schemaErrors(array $diagnostics): array
    {
        return array_values(array_filter(
            $diagnostics,
            static fn (Diagnostic $d): bool => $d->code === 'document.schema-invalid',
        ));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function uirVersion(array $document): string
    {
        return Hydrate::stringOr($document['uir'] ?? null, '1.0.0');
    }
}
