<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\EmitReport;
use Docuccino\Core\Emit\EmitResult;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and writes its UIR / OpenAPI artifact. Diagnostics print
 * grouped by route; the exit code honours `--fail-on`.
 */
final class ExportCommand extends Command
{
    use FailsOnSeverity;
    use GuardsEnabled;
    use IteratesDocuments;
    use RendersDiagnostics;

    /** Accepted --format values. */
    private const FORMATS = ['uir', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0'];

    protected $signature = 'docuccino:export
        {document? : The configured document key (defaults to every document)}
        {--format= : uir | openapi-3.2 | openapi-3.1 | openapi-3.0 (defaults to openapi-3.2)}
        {--out= : Output path (defaults to the document export path)}
        {--fail-on=none : none | warning | error — the severity that makes the command exit non-zero}
        {--provenance=winners : none | winners | full — UIR provenance detail}
        {--yaml : Emit YAML instead of JSON}';

    protected $description = 'Generate and export API documentation from your routes.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        // A typo errors out rather than falling back to OpenAPI 3.2 and shipping the wrong artifact.
        $format = $this->option('format');
        if (is_string($format) && $format !== '' && ! in_array($format, self::FORMATS, true)) {
            $this->error(sprintf('Unknown --format "%s"; expected one of: %s.', $format, implode(', ', self::FORMATS)));

            return self::FAILURE;
        }

        // One --out path can't hold several documents — later ones would clobber earlier ones.
        $out = $this->option('out');
        if (is_string($out) && $out !== '' && ! is_string($this->argument('document')) && count($builder->documentKeys()) > 1) {
            $this->error('--out cannot be used when exporting multiple documents; pass a document argument or configure per-document export.path.');

            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $engine): int {
            $result = $builder->build($key, $engine);

            $this->write($builder->config($key), $result->document);
            $this->renderDiagnostics($key, $result->diagnostics);

            return $this->failsOn($result) ? self::FAILURE : self::SUCCESS;
        });
    }

    private function write(DocumentConfig $config, UirDocument $document): void
    {
        $format = is_string($this->option('format')) && $this->option('format') !== ''
            ? $this->option('format')
            : 'openapi-3.2';
        $yaml = (bool) $this->option('yaml');

        $options = (new EmitOptions)->withYaml($yaml)->withProvenance($this->provenanceLevel());

        $result = match ($format) {
            'uir' => new EmitResult((new UirEmitter)->emit($document, $options), new EmitReport),
            'openapi-3.1' => (new OpenApi31DownlevelEmitter)->emitWithReport($document, $options),
            'openapi-3.0' => (new OpenApi30DownlevelEmitter)->emitWithReport($document, $options),
            default => new EmitResult((new OpenApi32Emitter)->emit($document, $options), new EmitReport),
        };

        $path = $this->outputPath($config);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($path, $result->output);
        $this->info(sprintf('Wrote %s (%s).', $path, $format));

        // A downlevel drops or approximates things; say so rather than shipping a quieter contract.
        $this->renderDiagnostics($format, $result->report->diagnostics);
    }

    private function outputPath(DocumentConfig $config): string
    {
        $out = $this->option('out');
        $path = is_string($out) && $out !== '' ? $out : $config->exportPath();

        return Paths::absolute($path, base_path());
    }

    private function provenanceLevel(): ProvenanceLevel
    {
        return ProvenanceLevel::tryFrom(is_string($this->option('provenance')) ? $this->option('provenance') : '')
            ?? ProvenanceLevel::Winners;
    }
}
