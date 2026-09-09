<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\Directory;
use Docuccino\Core\Support\NameList;
use Docuccino\Laravel\Config\ConfigMigration;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;

/**
 * Writes `docuccino.yaml` from the build settings still sitting in `config/docuccino.php` — the way
 * out of {@see ConfigSplit::NOT_MIGRATED}, which refuses a build whose configuration nothing reads.
 *
 * It writes ONE file and never edits the other. Rewriting `config/docuccino.php` was the obvious
 * shape and it is the wrong one: the framework keeps three of its keys, so it would be surgery on a
 * file somebody wrote rather than a file replaced, and the comments, formatting and `env()` calls in
 * it cannot be put back from a parsed array. It is also the author's only remaining copy of what they
 * configured while they check this file against it, and the leftovers are what keeps
 * {@see ConfigSplit::STALE_KEYS} warning until the tidy-up is done. So the deletion is named, printed
 * and left to them.
 *
 * The output carries only what its application configured — no defaults and no commented catalogue.
 * The shipped template shows every setting there is because it is a document about the product; this
 * is a file about one project, and a key written here that nobody set still joins the resolved
 * configuration and changes the document's fingerprint.
 *
 * Not a diagnostic anywhere, on {@see InstallCommand}'s terms: what this reports is about a machine's
 * setup rather than about a document.
 */
final class MigrateConfigCommand extends Command
{
    use GuardsEnabled;
    use PrintsSections;

    /** Named once, because the diagnostic that sends people here prints it too. */
    public const string NAME = 'docuccino:migrate-config';

    protected $signature = self::NAME.'
        {--force : Replace an existing docuccino.yaml with the settings from config/docuccino.php}
        {--dry-run : Print the file that would be written, and write nothing}';

    protected $description = 'Write docuccino.yaml from the build settings left in config/docuccino.php.';

    public function handle(): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $migration = ConfigMigration::of();
        $target = base_path(ConfigFile::NAME);

        if ($migration->nothing()) {
            return $this->reportNothingToMigrate($target);
        }

        $stopped = $this->write($migration, $target);
        if ($stopped !== null) {
            return $stopped;
        }

        $this->reportSettings($migration);

        // Exit 1 on an incomplete migration, with the file written. `config.not-migrated` is an error
        // precisely because a document assembled from something other than what its author wrote looks
        // plausible, and a migration that quietly left a route filter behind produces exactly that —
        // so a script that runs this has to hear about it in the one channel it can read.
        return $migration->complete() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Nothing to carry over, which is two different situations and only one of them wants advice.
     *
     * An application whose framework config holds no build settings has either finished migrating or
     * never configured anything, and both are supported states this must not talk them out of. With no
     * `docuccino.yaml` either, though, there is nothing at all — and `docuccino:install` is the command
     * that writes a file to start from, since there is nothing here to write one from.
     */
    private function reportNothingToMigrate(string $target): int
    {
        $this->line('config/docuccino.php holds no build settings, so there is nothing to migrate.');

        if (is_file($target)) {
            $this->line(sprintf(
                '<fg=gray>%s already holds this project\'s configuration, and was not touched.</>',
                $this->projectPath($target),
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<fg=gray>There is no %s either. `php artisan docuccino:install` writes one to start from.</>',
            ConfigFile::NAME,
        ));

        return self::SUCCESS;
    }

    /**
     * Put the file on disk, or say why it is not there. An exit code where the command is finished,
     * null where it should go on and report — and a refused overwrite is finished SUCCESSFULLY, since
     * honouring a decision somebody made is not a failure to do anything.
     */
    private function write(ConfigMigration $migration, string $target): ?int
    {
        $path = $this->projectPath($target);
        $contents = $migration->file();
        $unreadable = $migration->unreadable();

        if ($this->option('dry-run') === true) {
            $this->section(sprintf('%s, as it would be written', $path));
            // Line by line: the whole file through `TerminalText::of()` would have its own newlines
            // escaped and the file would print as one line.
            foreach (explode("\n", $contents) as $line) {
                $this->line(TerminalText::of($line));
            }

            return $unreadable === null ? null : $this->reportUnreadable($path, $unreadable);
        }

        // Before the file goes anywhere, because the next thing this prints is "delete
        // config/docuccino.php" and that file is the author's only other copy of these settings.
        if ($unreadable !== null) {
            return $this->reportUnreadable($path, $unreadable);
        }

        // The same timidity `docuccino:install` publishes under: an existing configuration file is a
        // decision somebody made. Sharper here, because this REPLACES rather than merges — folding two
        // files of settings together would owe a precedence rule between them, and precedence between
        // two configuration files is the thing this whole split exists to avoid.
        if (is_file($target) && $this->option('force') !== true) {
            $this->line(sprintf('%s is already there, and was left exactly as it is.', $path));
            $this->line('<fg=gray>Pass --force to replace it with the settings from config/docuccino.php.</>');
            $this->line('<fg=gray>It replaces the file rather than merging into it, so read what is there first.</>');

            return self::SUCCESS;
        }

        if (! Directory::ensure(dirname($target)) || ! AtomicFile::write($target, $contents)) {
            $this->error(sprintf('Could not write %s.', $path));

            return self::FAILURE;
        }

        $this->line(sprintf('Wrote %s from config/docuccino.php.', $path));

        return null;
    }

    /**
     * The migration could not express itself as a file the build reads, so nothing is written.
     *
     * Not a partial file and not a warning over one: a `docuccino.yaml` the build refuses replaces the
     * error this command was run to clear with another, and one holding something else entirely is worse
     * — it builds a plausible document from settings nobody wrote. Leaving the framework config as the
     * only copy is the recoverable answer.
     */
    private function reportUnreadable(string $path, string $reason): int
    {
        $this->error(sprintf('Could not write %s: these settings do not survive being written to it.', $path));
        $this->line(sprintf('  <fg=gray>%s</>', TerminalText::of($reason)));
        $this->newLine();
        $this->line('<fg=gray>Nothing was written and config/docuccino.php was not touched, so nothing is lost.</>');
        $this->line('<fg=gray>Report this: a build setting the framework config can hold and this file cannot is a bug.</>');

        return self::FAILURE;
    }

    /** Everything that happened to a setting on the way over, loudest last. */
    private function reportSettings(ConfigMigration $migration): void
    {
        if ($migration->renamed !== []) {
            $this->newLine();
            $this->line('Renamed on the way over, because the old spelling names no setting now:');
            foreach ($migration->renamed as $from => $to) {
                $this->line(sprintf('  <fg=gray>%s → %s</>', TerminalText::of($from), TerminalText::of($to)));
            }
        }

        if ($migration->dropped !== []) {
            $this->newLine();
            $this->line(sprintf(
                'Dropped, with the document unchanged: %s.',
                TerminalText::of(NameList::of($migration->dropped) ?? ''),
            ));
            $this->line(sprintf(
                '<fg=gray>%s has no key for %s, and %s empty or never read.</>',
                ConfigFile::NAME,
                count($migration->dropped) === 1 ? 'it' : 'them',
                count($migration->dropped) === 1 ? 'it was' : 'they were',
            ));
        }

        $this->reportEnvironment($migration);
        $this->reportLost($migration);

        $this->section('Next');
        $this->line(sprintf(
            'Delete the migrated settings from config/docuccino.php, which keeps only %s:',
            implode(', ', ConfigSplit::FRAMEWORK_KEYS),
        ));
        foreach (ConfigSplit::staleKeys() as $key) {
            $this->line(sprintf('  <fg=gray>%s</>', TerminalText::of($key)));
        }
        $this->newLine();
        $this->line('<fg=gray>Nothing left there is merged, so a build reports it until it is gone. Then run</>');
        $this->line('<fg=gray>php artisan docuccino:export and check the document against the one you had.</>');
    }

    /**
     * Which settings this machine's environment decided, since a resolved value is what `config()`
     * hands over and the `env()` call behind it cannot be recovered from it.
     */
    private function reportEnvironment(ConfigMigration $migration): void
    {
        if ($migration->environment !== []) {
            $this->newLine();
            $this->line('Read from this environment rather than from the file, so the value written is the');
            $this->line('one set where this ran:');
            foreach ($migration->environment as $path => $variable) {
                $this->line(sprintf('  <fg=gray>%s ← %s</>', TerminalText::of($path), TerminalText::of($variable)));
            }
            $this->line('<fg=gray>Each of those still overrides this file, so a single run can go on setting it.</>');
        }

        // Said whether or not any variable is set, and separately, because it is a different fact: the
        // one above is a value this machine decided, and this is every `env()` call whose indirection
        // did not survive being read. Which keys those were cannot be answered from a resolved value,
        // so the honest report is that they exist and where to look.
        if ($migration->resolved) {
            $this->newLine();
            $this->line('<fg=gray>config/docuccino.php reads settings through env(). Values arrive resolved, so any build</>');
            $this->line('<fg=gray>setting whose default came from the environment is written above as a literal — check</>');
            $this->line('<fg=gray>those against the file before deleting it.</>');
        }
    }

    /** The half the author has to act on: a setting the file could not express at all. */
    private function reportLost(ConfigMigration $migration): void
    {
        if ($migration->lost === []) {
            return;
        }

        $this->newLine();
        foreach ($migration->lost as $path => $cost) {
            $this->error(sprintf('%s was NOT carried over.', TerminalText::of($path)));
            $this->line(sprintf('  %s.', TerminalText::of($cost)));
        }

        $this->line(sprintf(
            '<fg=gray>Written into %s as a comment too, so it is still there when this scrolls away.</>',
            ConfigFile::NAME,
        ));
    }

    /** A path as the project names it, so nothing prints a machine layout it did not have to. */
    private function projectPath(string $path): string
    {
        return Paths::relative($path, base_path()) ?? $path;
    }
}
