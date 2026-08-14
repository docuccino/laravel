<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and checks it against the bundled UIR schema. The generator
 * already validates internally, so failures arrive as `document.schema-invalid` diagnostics; this
 * renders them grouped by route and exits non-zero, giving CI something to gate on.
 */
final class ValidateCommand extends Command
{
    use FailsOnSeverity;
    use GuardsEnabled;
    use IteratesDocuments;
    use RendersDiagnostics;

    protected $signature = 'docuccino:validate
        {document? : The configured document key (defaults to every document)}
        {--fail-on=none : none | warning | error — extra diagnostic severity that also fails (a schema violation always fails)}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Validate the generated document(s) against the bundled UIR schema.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $engine): int {
            $result = $builder->build($key, $engine);
            $schemaErrors = $this->schemaErrors($result->diagnostics);

            if ($schemaErrors === []) {
                $this->info(sprintf('%s: valid against UIR %s.', $key, $this->uirVersion($result->document->toArray())));
            } else {
                $this->error(sprintf('%s: %d schema violation(s).', $key, count($schemaErrors)));
            }

            $this->renderDiagnostics($key, $result->diagnostics);

            return $schemaErrors !== [] || $this->failsOn($result) ? self::FAILURE : self::SUCCESS;
        });
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
