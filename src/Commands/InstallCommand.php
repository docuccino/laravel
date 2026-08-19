<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Laravel\Config\ConfigPublisher;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\RoutePrefix;
use Docuccino\Laravel\Routing\RouteSurvey;
use Docuccino\Laravel\Support\ConsoleTable;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * Gets a fresh install from `composer require` to a document worth looking at: publishes the config,
 * says how many of THIS application's routes the shipped `api/*` pattern really matches (and where
 * the rest live when it matches none), reports whether the analysis engine is there, offers a first
 * export, and names what to do next.
 *
 * The one command that WRITES anything outside an export path, which is why the config half is
 * timid: an existing `config/docuccino.php` is a decision somebody made, and is never replaced
 * without `--force`. Everything else here is a read, so a second run reports the same and changes
 * nothing. None of it is a diagnostic — a diagnostic tells the document's author about the document,
 * and this tells an operator about their machine ({@see ExplainCommand} set the precedent).
 */
final class InstallCommand extends Command
{
    use GuardsEnabled;
    use PrintsSections;

    /** How many route prefixes are worth listing when nothing matched. */
    private const int PREFIX_LIMIT = 8;

    protected $signature = 'docuccino:install
        {--force : Replace an existing config/docuccino.php with the shipped defaults}
        {--no-export : Set up without generating a first document}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Set Docuccino up in this application and generate a first document.';

    public function handle(
        ConfigPublisher $publisher,
        DocumentBuilder $builder,
        EnginePackage $engine,
        RouteSurvey $survey,
        LaravelRouteResolver $resolver,
    ): int {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $this->section('Config');
        if (! $this->publishConfig($publisher)) {
            return self::FAILURE;
        }

        $this->section('Routes');
        $example = $this->reportRoutes($builder, $survey, $resolver, $publisher);

        $this->section('Engine');
        $this->reportEngine($engine);

        $this->section('First document');
        $exit = $this->firstExport();

        $this->section('Next');
        $this->reportNextSteps($builder, $example);

        return $exit;
    }

    /**
     * Publish `config/docuccino.php`, or say why it wasn't. False — having printed why — when the
     * write failed, which is the one thing here worth stopping for: every later step reports on a
     * config the application does not have.
     */
    private function publishConfig(ConfigPublisher $publisher): bool
    {
        $path = $this->projectPath($publisher->target());
        $existed = $publisher->published();

        if ($existed && $this->option('force') !== true) {
            $this->line(sprintf('%s is already there, and was left exactly as it is.', $path));
            $this->line('<fg=gray>Pass --force to replace it with the shipped defaults.</>');

            return true;
        }

        if (! $publisher->publish()) {
            $this->error(sprintf('Could not write %s.', $path));

            return false;
        }

        $this->line($existed
            ? sprintf('Replaced %s with the shipped defaults.', $path)
            : sprintf('Published %s.', $path));

        return true;
    }

    /**
     * What each document's `routes.include` matches HERE, which is the question the shipped `api/*`
     * cannot answer on its own. The count comes from the real resolver, so it is the number the next
     * export will document — attribute exclusions, closure filters and vendor routes all already
     * subtracted.
     *
     * Returns an operation worth naming in the next-steps block, or null when nothing matched.
     */
    private function reportRoutes(
        DocumentBuilder $builder,
        RouteSurvey $survey,
        LaravelRouteResolver $resolver,
        ConfigPublisher $publisher,
    ): ?RouteDescriptor {
        $candidates = count($survey->paths());

        if ($candidates === 0) {
            $this->line('This application publishes no routes Docuccino could document.');
            $this->line('<fg=gray>Routes from installed packages are left out by default — routes.include_vendor</>');
            $this->line('<fg=gray>brings them back. Otherwise there is nothing to fix here: add routes, then export.</>');

            return null;
        }

        $example = null;
        $empty = [];

        foreach ($builder->documentKeys() as $key) {
            $config = $builder->config($key);

            $matched = [];
            foreach ($resolver->resolve($config) as $descriptor) {
                $matched[] = $descriptor;
            }

            $example ??= $matched[0] ?? null;

            if ($matched === []) {
                $empty[] = $key;
            }

            $this->line(sprintf(
                '"%s" documents %d of the %d routes this application publishes (include: %s).',
                TerminalText::of($key),
                count($matched),
                $candidates,
                TerminalText::of($config->routeInclude === [] ? 'every route' : implode(', ', $config->routeInclude)),
            ));
        }

        if ($empty !== []) {
            $this->reportPrefixes($survey, $empty, $publisher);
        }

        return $example;
    }

    /**
     * Nothing matched, so the useful answer is where this application's routes actually are — the
     * one fact no documentation page can know. The prefixes are the router's own, so the pattern
     * printed underneath is copy-pasteable rather than illustrative.
     *
     * @param  non-empty-list<string>  $documents  the document keys that matched nothing
     */
    private function reportPrefixes(RouteSurvey $survey, array $documents, ConfigPublisher $publisher): void
    {
        $prefixes = $survey->prefixes();
        $busiest = $prefixes[0] ?? null;
        if ($busiest === null) {
            return;
        }

        $shown = array_slice($prefixes, 0, self::PREFIX_LIMIT);

        $this->newLine();
        $this->line(sprintf(
            '%s matched nothing. Your routes sit under:',
            implode(', ', array_map(static fn (string $key): string => '"'.TerminalText::of($key).'"', $documents)),
        ));
        $this->newLine();

        foreach (ConsoleTable::render(['Prefix', 'Routes'], array_map(
            static fn (RoutePrefix $prefix): array => [$prefix->pattern(), (string) $prefix->count],
            $shown,
        )) as $line) {
            $this->line($line);
        }

        if (count($prefixes) > count($shown)) {
            $this->line(sprintf('  <fg=gray>… and %d more.</>', count($prefixes) - count($shown)));
        }

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>Set documents.%s.routes.include in %s — e.g. [\'%s\'].</>',
            TerminalText::of($documents[0]),
            $this->projectPath($publisher->target()),
            TerminalText::of($busiest->pattern()),
        ));
    }

    /**
     * Whether anything will be inferred, and what it costs when nothing will. The wording follows the
     * `engine.not-installed` warning every export already prints rather than inventing a second one —
     * a reader who meets both should recognise the second as the same news.
     */
    private function reportEngine(EnginePackage $engine): void
    {
        if (config('docuccino.engine.mode') === TypeEngineMode::Null->value) {
            $this->line('Inference is switched off (engine.mode = null).');
            $this->line('<fg=gray>Documentation comes from docblocks and attributes only, as configured.</>');

            return;
        }

        if ($engine->installed()) {
            $this->line('The inference engine is installed.');
            $this->line('<fg=gray>Response shapes, query parameters and error responses are read from your code.</>');

            return;
        }

        $this->line('The inference engine is not installed; documentation will come from docblocks and');
        $this->line('attributes only.');
        $this->newLine();
        $this->line('  '.EnginePackage::INSTALL_COMMAND);
        $this->newLine();
        $this->line('<fg=gray>Without it, inferred response shapes, detected query parameters and automatic error</>');
        $this->line('<fg=gray>responses go quiet, and every export warns. Set DOCUCCINO_ENGINE=null to document</>');
        $this->line('<fg=gray>without inference and silence the warning.</>');
    }

    /**
     * The first export, delegated to `docuccino:export` so this command has no second opinion about
     * where an artifact goes. The prompt defaults to yes, which is also the answer `--no-interaction`
     * takes — a scripted setup that asked to be set up gets a document.
     */
    private function firstExport(): int
    {
        if ($this->option('no-export') === true || ! $this->confirm('Export one now?', true)) {
            $this->line('Skipped. <fg=gray>php artisan docuccino:export writes it when you are ready.</>');

            return self::SUCCESS;
        }

        $this->newLine();

        return $this->call('docuccino:export') === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function reportNextSteps(DocumentBuilder $builder, ?RouteDescriptor $example): void
    {
        $viewers = [];
        $artifacts = [];

        foreach ($builder->documentKeys() as $key) {
            $config = $builder->config($key);
            $artifacts[] = $this->projectPath(Paths::absolute($config->exportPath(), base_path()));

            $route = $config->viewer['route'] ?? null;
            if (is_string($route) && $route !== '') {
                $viewers[] = [$key, URL::to($route)];
            }
        }

        if ($viewers !== []) {
            foreach (ConsoleTable::render(['Document', 'Viewer'], $viewers) as $line) {
                $this->line($line);
            }

            $this->line('  <fg=gray>Open in your local environment; name a viewer.gate ability to open it elsewhere.</>');
            $this->newLine();
        }

        $signature = $example?->signature() ?? 'GET /api/invoices';

        foreach (ConsoleTable::render(['Command', 'What it answers'], [
            ['php artisan docuccino:export', 'rebuild the document'],
            ['php artisan docuccino:explain "'.$signature.'"', 'why one endpoint reads the way it does'],
            ['php artisan docuccino:diff', 'what changed since the committed artifact'],
        ]) as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>Commit %s: the output is byte-deterministic, so it diffs cleanly and docuccino:diff</>',
            implode(', ', array_unique($artifacts)),
        ));
        $this->line('<fg=gray>has something to compare against.</>');
    }

    /** A path as the project names it, so nothing prints a machine layout it did not have to. */
    private function projectPath(string $path): string
    {
        return Paths::relative($path, base_path()) ?? $path;
    }
}
