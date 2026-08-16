<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangesetRenderer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Diff\Policy\PolicyVerdict;
use Docuccino\Core\Diff\Policy\VersioningPolicies;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Semantically diffs a committed artifact against the freshly-generated document. The diff runs over
 * stable `x-docuccino.id`s ({@see DocumentDiffer}), so a path-param rename is no change while a URI
 * change is remove + add.
 *
 * `old` is a path to a committed UIR (preferred — it carries identities) or OpenAPI artifact, read from
 * the working tree unless `--against=<git-ref>` reads it via `git show <ref>:<old>`, in which case the
 * path must be repo-relative. `--enforce` runs the document's `versioning` policy over the changeset
 * severity and both `info.version`s, exiting non-zero on a violation.
 */
final class DiffCommand extends Command
{
    use GuardsEnabled;

    protected $signature = 'docuccino:diff
        {old : Path to the committed UIR/OpenAPI artifact to diff against}
        {document? : The configured document key to generate as the new side (defaults to "default")}
        {--against= : Read `old` from this git ref (git show <ref>:<old>) instead of the working tree}
        {--enforce : Enforce the document\'s versioning policy; exit non-zero on a violation}
        {--format=terminal : terminal | json}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    public function __construct(
        private readonly DocumentDiffer $differ = new DocumentDiffer,
        private readonly ChangesetRenderer $renderer = new ChangesetRenderer,
    ) {
        parent::__construct();
    }

    protected $description = 'Diff a committed API artifact against the current document (semantic, id-based).';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $key = $this->documentKey($builder);
        if ($key === null) {
            return self::FAILURE;
        }

        $old = $this->loadOld();
        if ($old === null) {
            return self::FAILURE;
        }

        $new = $builder->build($key, $engine)->document;

        try {
            $changeset = $this->differ->diff($old, $new);
        } catch (IncomparableDocumentsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $verdict = $this->option('enforce') ? $this->enforce($builder, $key, $changeset, $old, $new) : null;

        $this->render($key, $changeset, $verdict);

        return $verdict !== null && ! $verdict->satisfied ? self::FAILURE : self::SUCCESS;
    }

    private function documentKey(DocumentBuilder $builder): ?string
    {
        $document = $this->argument('document');
        $key = is_string($document) && $document !== '' ? $document : 'default';

        if (! $builder->hasDocument($key)) {
            $this->error(sprintf('Unknown document "%s".', $key));

            return null;
        }

        return $key;
    }

    private function loadOld(): ?UirDocument
    {
        $path = $this->argument('old');
        if (! is_string($path) || $path === '') {
            $this->error('The old artifact path is required.');

            return null;
        }

        $ref = $this->option('against');
        $json = is_string($ref) && $ref !== '' ? $this->readFromGit($ref, $path) : $this->readFromDisk($path);
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error(sprintf('Could not parse the old artifact as JSON: %s', $exception->getMessage()));

            return null;
        }

        // Valid JSON that isn't a document — `null`, a number, a string. Without this the hydrate call
        // raises a TypeError, which prints a stack trace of absolute paths into a CI log.
        if (! is_array($decoded)) {
            $this->error('Could not read the old artifact: its JSON is not an object.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return UirDocument::fromArray($decoded);
    }

    private function readFromDisk(string $path): ?string
    {
        $absolute = Paths::absolute($path, base_path());
        $contents = @file_get_contents($absolute);

        if ($contents === false) {
            $this->error(sprintf('Old artifact not found: %s', $absolute));

            return null;
        }

        return $contents;
    }

    private function readFromGit(string $ref, string $path): ?string
    {
        // Reject anything git would read as an option (`--upload-pack=…`), so a hostile argument can't
        // smuggle a flag past the `<ref>:<path>` operand.
        if (str_starts_with($ref, '-') || str_starts_with($path, '-')) {
            $this->error('The git ref and path must not start with "-".');

            return null;
        }

        // Array-form Process runs git directly with no shell, so nothing is word-split or expanded.
        $result = Process::run(['git', 'show', $ref.':'.$path]);

        if (! $result->successful()) {
            $this->error(sprintf('git show %s:%s failed: %s', $ref, $path, trim($result->errorOutput())));

            return null;
        }

        return $result->output();
    }

    private function enforce(DocumentBuilder $builder, string $key, Changeset $changeset, UirDocument $old, UirDocument $new): PolicyVerdict
    {
        $policy = VersioningPolicies::for($builder->config($key)->versioning);

        return $policy->evaluate($changeset, self::versionOf($old), self::versionOf($new));
    }

    private static function versionOf(UirDocument $document): string
    {
        return Hydrate::stringOr($document->info['version'] ?? null, '');
    }

    private function render(string $key, Changeset $changeset, ?PolicyVerdict $verdict): void
    {
        if ($this->option('format') === 'json') {
            $payload = ['document' => $key] + $changeset->toArray();
            if ($verdict !== null) {
                $payload['policy'] = $verdict->toArray();
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->output->write($this->renderer->render($changeset));

        if ($verdict === null) {
            return;
        }

        if ($verdict->satisfied) {
            $this->info(sprintf('Versioning policy "%s" satisfied.', $verdict->policy));
        } else {
            $suffix = $verdict->requiredVersion !== null ? sprintf(' (require ≥ %s)', $verdict->requiredVersion) : '';
            $this->error(sprintf('Versioning policy "%s" violated: %s%s', $verdict->policy, $verdict->message, $suffix));
        }
    }
}
