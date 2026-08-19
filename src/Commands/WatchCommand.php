<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Support\TerminalText;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;
use Docuccino\Laravel\Watch\BuildRunner;
use Docuccino\Laravel\Watch\BuildToken;
use Docuccino\Laravel\Watch\ChangePoller;
use Docuccino\Laravel\Watch\ChangeSummary;
use Docuccino\Laravel\Watch\WatchSet;
use Docuccino\Laravel\Watch\WatchSignal;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

/**
 * Rebuilds documentation as the files a build actually depends on change, and pushes a refresh to an
 * open viewer.
 *
 * The watch set comes from the fragment cache rather than from a pattern: every fragment records the
 * files its operation was recovered from, so one controller edit rebuilds one operation
 * ({@see WatchSet}). Each rebuild runs in a fresh process, which is what lets it see edited code at
 * all ({@see ArtisanBuildRunner}), and the refresh reaches the browser through a token on disk
 * ({@see WatchSignal}) because the viewer is served by a different process again.
 *
 * Nothing here can change an emitted byte: the loop drives `docuccino:export` and reads what it
 * wrote, so a watched build and a one-off build are the same build.
 */
final class WatchCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;

    protected $signature = 'docuccino:watch
        {document? : The configured document key (defaults to every document)}
        {--interval=1 : Seconds between polls of the watched files}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Rebuild API documentation as your code changes, and refresh an open viewer.';

    /** Set from a signal handler; every loop in here checks it rather than exiting where it stands. */
    private bool $stopping = false;

    public function handle(DocumentBuilder $builder, WatchSet $watched, WatchSignal $signal, BuildToken $tokens, BuildRunner $runner, Application $app, FragmentStore $fragments): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $documents = $this->selectedDocuments($builder);
        $interval = $this->interval();

        if ($documents === null || $interval === null) {
            return self::FAILURE;
        }

        if ($documents === []) {
            $this->error('No documents are configured, so there is nothing to watch.');

            return self::FAILURE;
        }

        $this->listenForInterrupt();

        $this->line(sprintf(
            'Watching %s. Press Ctrl+C to stop.',
            TerminalText::of(implode(', ', $documents)),
        ));

        // Up front rather than with the rest of the watch-set report: it is knowable before the first
        // build, and it is the one thing worth stopping to fix before sitting through one.
        $pinnedOff = self::fragmentCacheIsPinnedOff($app, $fragments);
        if ($pinnedOff) {
            $this->warnFragmentCacheIsPinnedOff();
        }

        $poller = new ChangePoller($watched, $interval);
        $published = null;
        $announced = false;

        try {
            while (! $this->stopping) {
                $runner->build($this->getOutput(), $this->documentArgument(), $this->memoryLimit());

                $token = $tokens->of($documents);
                if ($token !== $published) {
                    $signal->publish($token);
                    $published = $token;
                    $this->line('<fg=gray>Pushed a refresh to any open viewer.</>');
                }

                $roots = $watched->roots($documents);
                if (! $announced) {
                    $this->reportWatchSet($watched, $roots, $pinnedOff);
                    $announced = true;
                }

                $changed = $poller->await($roots, fn (): bool => $this->stopping);
                if ($changed === []) {
                    break;
                }

                $this->newLine();
                $this->line(ChangeSummary::of($changed, base_path()));
            }
        } finally {
            // Whether the loop ended on Ctrl+C or on a broken build: the endpoint goes away with the
            // session that published it, so a viewer never reconnects to a watcher that isn't there.
            $signal->clear();
        }

        $this->newLine();
        $this->info('Stopped watching.');

        return self::SUCCESS;
    }

    /**
     * What the session is watching, said once. The operation count is the load-bearing half: it is
     * zero when no fragment was stored, and a watch set with no operation files in it cannot notice
     * a controller changing.
     *
     * $pinnedOff only decides which advice is worth giving here — it has already been said, since a
     * count alone does not give it away: fragments left behind by an earlier session read as a
     * healthy watch set right up until the first edit that should have rebuilt and didn't.
     *
     * @param  list<string>  $roots
     */
    private function reportWatchSet(WatchSet $watched, array $roots, bool $pinnedOff): void
    {
        $operations = count($watched->operationFiles());

        if ($operations === 0) {
            $this->warn('No operation fragments were stored, so only config, routes, content, webhooks and overlays are watched — editing a controller will not rebuild.');

            if (! $pinnedOff) {
                $this->line('<fg=gray>The fragment cache could not be turned on for the build. Check that docuccino.cache.path is writable, or set docuccino.cache.enabled to true.</>');
            }

            return;
        }

        $this->line(sprintf(
            '<fg=gray>Watching %d file(s) behind your operations, and %d other root(s).</>',
            $operations,
            count($roots) - $operations,
        ));
    }

    /**
     * Said before the first build rather than after it, because it makes every rebuild in the session
     * a cold one and there is a two-command fix.
     */
    private function warnFragmentCacheIsPinnedOff(): void
    {
        $this->warn('Your configuration is cached, so this session cannot turn the fragment cache on: DOCUCCINO_FRAGMENT_CACHE was read when you cached, and each rebuild reads the baked value rather than the one set for it.');
        $this->line('<fg=gray>Rebuilds will re-analyse everything and store nothing, so editing a controller will not rebuild. Run `php artisan config:clear`, or set DOCUCCINO_FRAGMENT_CACHE=true and cache again.</>');
    }

    /**
     * Whether a rebuild's fragment cache is off in a way this session cannot fix.
     *
     * `config:cache` bakes `env('DOCUCCINO_FRAGMENT_CACHE')` in at cache time, so the env override
     * {@see ArtisanBuildRunner} hands the child process is read by nothing. The store is consulted
     * as well as the cache flag because it holds exactly the value the child will read: where the
     * flag was baked TRUE the session works, and the warning stays quiet.
     */
    private static function fragmentCacheIsPinnedOff(Application $app, FragmentStore $fragments): bool
    {
        return $app->configurationIsCached() && ! $fragments->enabled;
    }

    /**
     * Ctrl+C (and a `kill`) end the session between builds rather than wherever they land, so the
     * signal file goes and no half-written artifact is left behind. Without ext-pcntl there is no
     * handler to install and the process simply dies on the default handler.
     */
    private function listenForInterrupt(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $this->stop(...));
        pcntl_signal(SIGTERM, $this->stop(...));
    }

    private function stop(): void
    {
        $this->stopping = true;
    }

    /** The poll interval in seconds; null (after printing why) when the flag names something else. */
    private function interval(): ?float
    {
        $value = $this->option('interval');
        $value = is_string($value) ? trim($value) : '';

        if (! is_numeric($value) || (float) $value <= 0) {
            $this->error(sprintf('Unknown --interval "%s"; expected a number of seconds greater than zero.', $value));

            return null;
        }

        return (float) $value;
    }

    private function documentArgument(): ?string
    {
        $document = $this->argument('document');

        return is_string($document) ? $document : null;
    }

    private function memoryLimit(): ?string
    {
        $limit = $this->option('memory-limit');

        return is_string($limit) && $limit !== '' ? $limit : null;
    }
}
