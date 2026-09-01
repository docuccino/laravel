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
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Semantically diffs a committed artifact against the freshly-generated document. The diff runs over
 * stable `x-docuccino.id`s ({@see DocumentDiffer}), so a path-param rename is no change while a URI
 * change is remove + add.
 *
 * Nothing printed here was written by this command: it comes off an artifact nobody re-read first, off an
 * argument, or off git's stderr. What core already rendered arrives `PlainText`-clean and owes only
 * the markup half ({@see TerminalText::markupOnly()}); everything this command interpolates itself owes
 * both ({@see TerminalText::of()}). `--format=json` is the exception: nothing there is read by a terminal,
 * so it goes out raw and `json_encode`'s own escaping is the whole of its safety.
 *
 * `old` is a path to a committed UIR (preferred — it carries identities) or OpenAPI artifact, read from
 * the working tree unless `--against=<git-ref>` reads it via `git show <ref>:<old>`, in which case the
 * path must be repo-relative. `--enforce` runs the document's `versioning` policy over the changeset
 * severity and both `info.version`s, exiting non-zero on a violation.
 */
final class DiffCommand extends Command
{
    use GuardsEnabled;
    use ReadsCommittedArtifact;
    use StringOptions;

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
            $this->error(TerminalText::markupOnly($exception->getMessage()));

            return self::FAILURE;
        }

        $verdict = $this->option('enforce') ? $this->enforce($builder, $key, $changeset, $old, $new) : null;

        if (! $this->render($key, $changeset, $verdict)) {
            return self::FAILURE;
        }

        return $verdict !== null && ! $verdict->satisfied ? self::FAILURE : self::SUCCESS;
    }

    private function documentKey(DocumentBuilder $builder): ?string
    {
        $document = $this->argument('document');
        $key = is_string($document) && $document !== '' ? $document : 'default';

        if (! $builder->hasDocument($key)) {
            $this->error(sprintf('Unknown document "%s".', TerminalText::of($key)));

            return null;
        }

        return $key;
    }

    private function loadOld(): ?UirDocument
    {
        $path = $this->argument('old');

        return $this->committedArtifact(is_string($path) ? $path : '', $this->stringOption('against'));
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

    /** False when the payload could not be rendered at all, which the caller reports as a failed run. */
    private function render(string $key, Changeset $changeset, ?PolicyVerdict $verdict): bool
    {
        if ($this->option('format') === 'json') {
            $payload = ['document' => $key] + $changeset->toArray();
            if ($verdict !== null) {
                $payload['policy'] = $verdict->toArray();
            }

            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // The payload carries document data, so a value JSON cannot spell — a string that is not
            // valid UTF-8, an `INF` — reaches here. An empty line is the one answer a CI gate cannot
            // read as anything: it parses as neither a changeset nor a failure.
            if ($encoded === false) {
                $this->error(TerminalText::of(sprintf(
                    'The changeset for "%s" could not be encoded as JSON: %s. Run without --format=json to read it.',
                    $key,
                    json_last_error_msg(),
                )));

                return false;
            }

            // Raw, because this half is machine-readable: `line()` writes at OUTPUT_NORMAL, where the
            // formatter reads `<…>` in an artifact-derived name as markup and drops it — still valid JSON,
            // and no longer the data a CI gate is deciding on.
            $this->output->writeln($encoded, OutputInterface::OUTPUT_RAW);

            return true;
        }

        $this->output->write(TerminalText::markupOnly($this->renderer->render($changeset)));

        if ($verdict === null) {
            return true;
        }

        if ($verdict->satisfied) {
            $this->info(TerminalText::markupOnly(sprintf('Versioning policy "%s" satisfied.', $verdict->policy)));
        } else {
            $suffix = $verdict->requiredVersion !== null ? sprintf(' (require ≥ %s)', $verdict->requiredVersion) : '';
            $this->error(TerminalText::markupOnly(sprintf('Versioning policy "%s" violated: %s%s', $verdict->policy, $verdict->message, $suffix)));
        }

        return true;
    }
}
