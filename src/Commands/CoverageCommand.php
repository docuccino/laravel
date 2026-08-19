<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageMerge;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\ArtifactLocator;
use Docuccino\Laravel\Support\CoverageLogPath;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;
use JsonException;

/**
 * Reports which documented operations the test suite exercised, out of the logs the suite wrote.
 *
 * It is a command rather than an assertion because coverage is a question about the WHOLE suite, and no
 * test can see the whole suite: a parallel worker holds its own share, a shard holds its own machine's,
 * and neither can know when the others finished. So the suite writes and this merges afterwards —
 * exactly the shape line coverage has, where workers write and the runner merges when they are done.
 *
 * `--path` is repeatable because the merge is the only place four shards' logs ever meet: each uploads
 * its directory, and a final job names all four here.
 */
final class CoverageCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;
    use PrintsSections;

    /**
     * How far apart the logs may be written before it is worth saying so, in seconds.
     *
     * Ten minutes, because below that a run and two runs back to back are not distinguishable and the
     * line would fire on ordinary single runs. Above it, the line states a fact rather than an
     * accusation: a suite that really does take a quarter of an hour reads it and moves on.
     */
    private const int LONG_SPAN = 600;

    protected $signature = 'docuccino:coverage
        {document? : The configured document key (defaults to every document)}
        {--path=* : A coverage log directory to merge (repeatable; defaults to the document\'s own)}
        {--min=0 : Fail below this percentage of documented operations}
        {--reset : Delete the logs and exit, leaving the directory ready for a run}';

    protected $description = 'Report which documented operations your test suite exercised.';

    public function handle(DocumentBuilder $builder): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $minimum = $this->minimum();

        if ($minimum === null) {
            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $minimum): int {
            $config = $builder->config($key);
            $directories = $this->directories($config);

            return $this->option('reset') === true
                ? $this->reset($key, $directories)
                : $this->report($key, $config, $directories, $minimum);
        });
    }

    /**
     * @param  list<string>  $directories
     */
    private function report(string $key, DocumentConfig $config, array $directories, float $minimum): int
    {
        $merge = CoverageMerge::of($directories);

        $this->section(sprintf('Coverage — %s', $key), implode('  ', $directories));

        // A number computed from three of four shards is worse than no number, so the incomplete merge
        // never reaches the report — the reader is told what is missing and the gate stays shut.
        if (! $merge->complete()) {
            $this->newLine();
            $this->error('The coverage logs are incomplete, so there is nothing here a gate may read.');

            foreach ($this->problems($merge) as $problem) {
                $this->line('  '.TerminalText::of($problem));
            }

            $this->newLine();
            $this->line('Run your suite with the recorder on (ApiContract::recordCoverage() in the test bootstrap),');
            $this->line('or name the directories to merge: --path=<dir> --path=<dir>');

            return self::FAILURE;
        }

        $index = $this->index($config);

        if ($index === null) {
            return self::FAILURE;
        }

        $report = CoverageReport::of($index, $merge->ids);

        $this->line(sprintf('<fg=gray>%d log files, %d ids</>', count($merge->files), count($merge->ids)));

        // Logs accumulate until something resets them, and a merge of three runs reads exactly like a
        // merge of one — too GENEROUS, which for a gate is the worse direction, and nothing in the
        // numbers above betrays it. The one thing that does is how far apart the files were written.
        if ($merge->span >= self::LONG_SPAN) {
            $this->line(sprintf(
                '<fg=yellow>These logs span %s. Unless your suite does too, they are several runs unioned — reset before the run.</>',
                self::duration($merge->span),
            ));
        }

        $this->newLine();

        // Line by line rather than as one blob: a console writer is free to wrap, and a reader
        // scrolling for the operation they own wants it on a line of its own.
        foreach (explode("\n", TerminalText::markupOnly($report->render($minimum > 0 ? $minimum : null, 'php artisan docuccino:export --format=uir'))) as $line) {
            $this->line($line);
        }

        return $report->meets($minimum) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Empty the directories, so the next run's logs are the next run's.
     *
     * Names are unique per writing process — two shards on one machine must never overwrite each other
     * — and the cost of that is that runs accumulate rather than replace. This is the answer, and it
     * only ever unlinks the log files {@see CoverageLog::scan()} reports: regular files, ending in the
     * log extension, never a link and never a directory.
     *
     * @param  list<string>  $directories
     */
    private function reset(string $key, array $directories): int
    {
        $removed = 0;
        foreach ($directories as $directory) {
            foreach (CoverageLog::scan($directory)->files as $file) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        $this->info(sprintf('%s: removed %d coverage log(s).', $key, $removed));

        return self::SUCCESS;
    }

    /**
     * The contract to measure against: the artifact the suite asserted against, read rather than
     * rebuilt, so the command and the assertions can only ever be talking about the same operations.
     */
    private function index(DocumentConfig $config): ?ContractIndex
    {
        $path = ArtifactLocator::locate($config, base_path());
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->newLine();
            $this->error(sprintf('There is no artifact at %s to measure coverage against.', TerminalText::of($path)));
            $this->line('Export one: php artisan docuccino:export');

            return null;
        }

        try {
            return ContractIndex::fromJson($contents);
        } catch (JsonException $exception) {
            $this->newLine();
            $this->error(sprintf(
                '%s is not a JSON document (%s).',
                TerminalText::of($path),
                TerminalText::of($exception->getMessage()),
            ));
            $this->line('Regenerate it: php artisan docuccino:export');

            return null;
        }
    }

    /**
     * The directories to merge: the ones named, or the document's own.
     *
     * @return list<string>
     */
    private function directories(DocumentConfig $config): array
    {
        /** @var list<string> $paths */
        $paths = array_values(array_filter(
            (array) $this->option('path'),
            static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
        ));

        if ($paths === []) {
            return [CoverageLogPath::resolve($config, base_path())];
        }

        return array_map(static fn (string $path): string => Paths::absolute(trim($path), base_path()), $paths);
    }

    /**
     * Each incomplete part of the merge, in one line the reader can act on.
     *
     * @return list<string>
     */
    private function problems(CoverageMerge $merge): array
    {
        $problems = [];

        foreach ($merge->missing as $directory) {
            $problems[] = sprintf(
                '%s — could not be read (a shard whose logs never arrived, or a directory this job cannot open)',
                $directory,
            );
        }

        foreach ($merge->empty as $directory) {
            $problems[] = sprintf('%s — holds no coverage log', $directory);
        }

        foreach ($merge->unreadable as $file) {
            $problems[] = sprintf('%s — not readable as a coverage log', $file);
        }

        return $problems;
    }

    /** `14m`, `2h 34m` — only ever called above {@see LONG_SPAN}, so there are no seconds to lose. */
    private static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours === 0 ? sprintf('%dm', $minutes) : sprintf('%dh %dm', $hours, $minutes);
    }

    /** The floor, or null having said why it is not a percentage. */
    private function minimum(): ?float
    {
        $value = $this->option('min');
        $value = is_string($value) ? trim($value) : '0';

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            $this->error(sprintf('--min must be a percentage between 0 and 100, not "%s".', TerminalText::of($value)));

            return null;
        }

        return (float) $value;
    }
}
