<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\Psr4Namespaces;
use Docuccino\Laravel\Support\TerminalText;
use Docuccino\Laravel\Versioning\ChangeDirectories;
use Docuccino\Laravel\Versioning\Scaffold\ChangeDestination;
use Docuccino\Laravel\Versioning\Scaffold\ChangePlacement;
use Docuccino\Laravel\Versioning\Scaffold\ChangeScaffolder;
use Docuccino\Laravel\Versioning\Scaffold\ChangeStub;
use Docuccino\Laravel\Versioning\Scaffold\ScaffoldedChange;
use Illuminate\Console\Command;

/**
 * Drafts the `#[ApiVersionChange]` classes for the version being cut, out of the diff between the
 * document the previous version published and the one this code builds.
 *
 * Nothing here is new machinery: the git read and the artifact reader are `docuccino:diff`'s
 * ({@see ReadsCommittedArtifact}), the comparison is {@see DocumentDiffer} over the same stable
 * identities, and the vocabulary is the one the build already reads. What it adds is the part a diff
 * cannot do on its own — turning a difference into a declaration, with the difference's own factual
 * sentence as the starting `description`. The author supplies the WHY, which is the one thing nothing
 * else knows.
 *
 * Its own command rather than a step of `docuccino:install`: `install` runs once and idempotently,
 * while scaffolding a version is recurring work done when one is cut.
 *
 * Where each change is written is {@see ChangePlacement}'s answer — the module that owns the class the
 * verb names, where a configured entry's wildcard declared one — and it is REPORTED for every change,
 * with the reason, whether a module was found or not. A destination chosen quietly is the failure mode
 * worth designing against: the class is discovered wherever it lands, so a wrong module costs nothing
 * until somebody extracts one and its history goes with the other half.
 *
 * @phpstan-type PlacedChange array{change: ScaffoldedChange, destination: ChangeDestination, namespace: string}
 */
final class VersionChangesCommand extends Command
{
    use GuardsEnabled;
    use PrintsSections;
    use ReadsCommittedArtifact;
    use StringOptions;

    protected $signature = 'docuccino:version-changes
        {old : Path to the committed UIR artifact of the version this one diverges from}
        {document? : The configured document key to build as the new side (defaults to "default")}
        {--against= : Read `old` from this git ref (git show <ref>:<old>) instead of the working tree}
        {--since= : The version the scaffolded changes shipped in (defaults to the document\'s info.version)}
        {--in= : Write every class into this configured api_version.changes directory, whatever owns it}
        {--dry-run : Report what would be written, and write nothing}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Scaffold the version-change classes for the differences between a published version and the current build.';

    public function __construct(
        private readonly DocumentDiffer $differ = new DocumentDiffer,
        private readonly ChangeScaffolder $scaffolder = new ChangeScaffolder,
    ) {
        parent::__construct();
    }

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $key = $this->documentKey($builder);
        if ($key === null) {
            return self::FAILURE;
        }

        $path = $this->argument('old');
        $old = $this->committedArtifact(is_string($path) ? $path : '', $this->stringOption('against'));
        if ($old === null) {
            return self::FAILURE;
        }

        $config = $builder->config($key);

        $since = $this->stringOption('since') ?? $config->apiVersion();
        if ($since === null) {
            $this->error(sprintf(
                'The "%s" document states no version to scaffold against. Set its info.version, or pass --since.',
                TerminalText::of($key),
            ));

            return self::FAILURE;
        }

        [$directories, , $modules] = ChangeDirectories::resolve(base_path(), $config);

        if ($directories === []) {
            $this->error(sprintf(
                'The "%s" document configures no api_version.changes directory, so there is nowhere to write a change class.',
                TerminalText::of($key),
            ));

            return self::FAILURE;
        }

        $forced = $this->forced($directories);
        if ($forced === false) {
            return self::FAILURE;
        }

        $new = $builder->build($key, $engine);

        return $this->scaffold($old, $new, $since, new ChangePlacement(base_path(), $directories, $modules, $forced), $key);
    }

    /**
     * Diff, plan, write, report. One method because the report is the product here as much as the files
     * are: a difference the vocabulary cannot express is only useful to the author if it is printed
     * beside the ones that were.
     */
    private function scaffold(UirDocument $old, GenerationResult $new, string $since, ChangePlacement $placement, string $key): int
    {
        try {
            $changeset = $this->differ->diff($old, $new->document);
        } catch (IncomparableDocumentsException $exception) {
            $this->error(TerminalText::markupOnly($exception->getMessage()));

            return self::FAILURE;
        }

        $plan = $this->scaffolder->plan($changeset, $old, $new->document, $new->schemaSources, $since);
        $stub = new ChangeStub(base_path());

        $this->section('Scaffold', sprintf(
            '"%s" at %s, from the %s stub.',
            $key,
            $since,
            $stub->published() ? 'published' : 'packaged',
        ));

        if ($plan->changes === []) {
            $this->line('Nothing the version-change vocabulary expresses.');
        }

        $placed = $this->placed($plan->changes, $placement);
        if ($placed === null) {
            return self::FAILURE;
        }

        $written = [];
        $skipped = [];

        foreach ($placed as $entry) {
            $file = $entry['change']->file($entry['destination']->directory);

            // Never overwritten, and never merged into either: the file is the author's the moment it
            // exists, and the sentence they wrote in it is the whole value of the thing.
            if (is_file($file)) {
                $skipped[] = $entry;

                continue;
            }

            if (! $this->option('dry-run') && ! $this->write($file, $stub->render($entry['change'], $entry['namespace']))) {
                return self::FAILURE;
            }

            $written[] = $entry;
        }

        $this->report($written, $skipped, $plan->gaps);

        return self::SUCCESS;
    }

    /**
     * Every change against where it goes and the namespace it carries there, or null when a destination
     * is one no PSR-4 prefix covers — which fails the whole run before a single file is written. A
     * change class is found by scanning source and then loading it, so one the autoloader cannot map is
     * a change nothing applies, silently; and half a version declared is worse than none, because the
     * half that is missing looks like a version where nothing else changed.
     *
     * @param  list<ScaffoldedChange>  $changes
     * @return list<PlacedChange>|null
     */
    private function placed(array $changes, ChangePlacement $placement): ?array
    {
        $placed = [];
        $unmapped = [];

        foreach ($changes as $change) {
            $destination = $placement->for($change->schema);
            $namespace = Psr4Namespaces::for(base_path(), $destination->directory);

            if ($namespace === null) {
                $unmapped[$this->readable($destination->directory)] = true;

                continue;
            }

            $placed[] = ['change' => $change, 'destination' => $destination, 'namespace' => $namespace];
        }

        if ($unmapped !== []) {
            $this->error(sprintf(
                'No PSR-4 prefix in composer.json covers %s, so a class written there would never be autoloaded — and a change nothing loads is a change nothing applies. Map the directory and run this again.',
                TerminalText::of(implode(', ', array_keys($unmapped))),
            ));

            return null;
        }

        return $placed;
    }

    /**
     * @param  list<PlacedChange>  $written
     * @param  list<PlacedChange>  $skipped
     * @param  list<string>  $gaps
     */
    private function report(array $written, array $skipped, array $gaps): void
    {
        if ($written !== []) {
            $this->section($this->option('dry-run') ? 'Would write' : 'Written');

            foreach ($written as $entry) {
                $change = $entry['change'];

                $this->line(sprintf('  %s — %s', TerminalText::of($change->class), TerminalText::of($change->description)));
                $this->line(sprintf('    <fg=gray>%s</>', TerminalText::of($this->where($entry['destination']))));

                // A narrowed change is the one thing a reader would not expect, printed as it was
                // written: the operations it names are the operations it will rewrite, and the ones it
                // does not name keep the shape the code publishes.
                foreach ($change->scope as $applies) {
                    $this->line(sprintf('    <fg=gray>%s</>', TerminalText::of($applies)));
                }

                if ($change->note !== null) {
                    $this->line(sprintf('    <fg=yellow>%s</>', TerminalText::of($change->note)));
                }
            }

            $this->newLine();
            $this->line('Each description says WHAT changed. Add why it changed, and whom it affects — that half is');
            $this->line('the reason the declaration exists, and it is the half nothing here can write for you.');
        }

        if ($skipped !== []) {
            $this->section('Left alone', 'A class of that name is already there, and it is yours.');

            foreach ($skipped as $entry) {
                $this->line(sprintf('  %s', TerminalText::of($entry['change']->class)));
                $this->line(sprintf('    <fg=gray>%s</>', TerminalText::of($this->where($entry['destination']))));
            }
        }

        if ($gaps !== []) {
            $this->section('Not declared', 'Real differences the vocabulary does not express. Nothing was written for them.');

            foreach ($gaps as $gap) {
                $this->line(sprintf('  %s', TerminalText::of($gap)));
            }
        }
    }

    /** False when the file could not be written, which is reported here and fails the run. */
    private function write(string $file, string|false $contents): bool
    {
        if ($contents === false) {
            $this->error('The version-change stub could not be read, so nothing was written.');

            return false;
        }

        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) {
            $this->error(sprintf('Could not create %s.', TerminalText::of($this->readable(dirname($file)))));

            return false;
        }

        if (@file_put_contents($file, $contents) === false) {
            $this->error(sprintf('Could not write %s.', TerminalText::of($this->readable($file))));

            return false;
        }

        return true;
    }

    /** One destination as the report prints it: where, and why there. */
    private function where(ChangeDestination $destination): string
    {
        return sprintf('into %s — %s.', $this->readable($destination->directory), $destination->reason);
    }

    /**
     * The directory `--in` names, null when it named none, or false when what it named is not one of
     * this document's — which is a refusal rather than a nearest match, since the flag exists to
     * override a placement and a typo would silently override it somewhere else.
     *
     * @param  list<string>  $directories
     */
    private function forced(array $directories): string|false|null
    {
        $wanted = $this->stringOption('in');
        if ($wanted === null) {
            return null;
        }

        foreach ($directories as $directory) {
            if ($directory === $wanted || $this->readable($directory) === trim($wanted, '/')) {
                return $directory;
            }
        }

        $this->error(sprintf(
            '--in=%s names none of this document\'s change directories: %s.',
            TerminalText::of($wanted),
            TerminalText::of(implode(', ', array_map($this->readable(...), $directories))),
        ));

        return false;
    }

    /** A path as the author wrote it: relative to the application, since that is what config holds. */
    private function readable(string $path): string
    {
        return Paths::relative($path, base_path()) ?? $path;
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
}
