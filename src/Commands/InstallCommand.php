<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\ConfigPublisher;
use Docuccino\Laravel\Config\ConfigPublishers;
use Docuccino\Laravel\Config\ConfigSplit;
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
 * The config half is timid: an existing configuration file is a decision somebody made, and neither
 * of the two is ever replaced without `--force`. Build settings still sitting in `config/docuccino.php`
 * are a decision too, so an application holding those gets `docuccino.yaml` written from THEM — by
 * {@see MigrateConfigCommand}, which owns that — rather than from the shipped defaults. Everything
 * else here is a read, so a second run reports the same and changes nothing. None of it is a
 * diagnostic — a diagnostic tells the document's author about the document, and this tells an operator
 * about their machine ({@see ExplainCommand} set the precedent).
 */
final class InstallCommand extends Command
{
    use GuardsEnabled;
    use PrintsSections;

    /** How many route prefixes are worth listing when nothing matched. */
    private const int PREFIX_LIMIT = 8;

    protected $signature = 'docuccino:install
        {--force : Replace existing configuration files with the shipped defaults}
        {--no-export : Set up without generating a first document}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Set Docuccino up in this application and generate a first document.';

    public function handle(
        ConfigPublishers $publishers,
        DocumentBuilder $builder,
        EnginePackage $engine,
        RouteSurvey $survey,
        LaravelRouteResolver $resolver,
    ): int {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $this->section('Config');
        if (! $this->publishConfig($publishers)) {
            return self::FAILURE;
        }

        $this->section('Routes');
        $example = $this->reportRoutes($builder, $survey, $resolver, $publishers);

        $this->section('Engine');
        $this->reportEngine($engine);

        $this->section('First document');
        $exit = $this->firstExport();

        $this->section('Next');
        $this->reportNextSteps($builder, $example);

        return $exit;
    }

    /**
     * Publish both configuration files, or say why one wasn't. False — having printed why — when a
     * write failed, which is the one thing here worth stopping for: every later step reports on a
     * config the application does not have.
     *
     * Each file is timid on its own account, so an application that already keeps its own
     * `docuccino.yaml` and has never published the framework half gets the half it is missing.
     */
    private function publishConfig(ConfigPublishers $publishers): bool
    {
        foreach ($publishers->all() as $publisher) {
            if (! $this->publishOne($publisher)) {
                return false;
            }
        }

        return true;
    }

    private function publishOne(ConfigPublisher $publisher): bool
    {
        $path = $this->projectPath($publisher->target());
        $existed = $publisher->published();

        if (! $existed && $this->option('force') !== true && $this->owesMigration($publisher)) {
            return $this->migrate($publisher);
        }

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
     * Whether this publisher would write the shipped `docuccino.yaml` over settings an application
     * already has, which is a state the timidity rule covers and the file name cannot see.
     *
     * An existing file is a decision somebody made — and so are build settings sitting in
     * `config/docuccino.php`. Publishing defaults there answers confidently and wrongly in the one way
     * that is hard to recover from: the file appears, so nothing refuses the build any more, the
     * document is assembled from defaults, and the `config.stale-php-keys` warning that follows tells
     * its reader to delete the only remaining copy of what they configured.
     */
    private function owesMigration(ConfigPublisher $publisher): bool
    {
        return basename($publisher->target()) === ConfigFile::NAME && ConfigSplit::staleKeys() !== [];
    }

    /**
     * Write that file from the settings the application already has, by running the one command that
     * knows how — rather than holding a second opinion about what belongs in it.
     *
     * Its exit code is not read: a setting it could not carry over is news for the operator, not a
     * reason for the setup to stop. Whether the file arrived is read instead, because every step after
     * this one reports on a configuration the application would not have.
     */
    private function migrate(ConfigPublisher $publisher): bool
    {
        $stale = ConfigSplit::staleKeys();

        $this->line(sprintf(
            'config/docuccino.php holds %d build setting%s the build no longer reads, so %s is written',
            count($stale),
            count($stale) === 1 ? '' : 's',
            ConfigFile::NAME,
        ));
        $this->line('from those rather than from the shipped defaults.');
        $this->newLine();

        $this->call(MigrateConfigCommand::NAME);

        if ($publisher->published()) {
            return true;
        }

        $this->error(sprintf('Could not write %s.', $this->projectPath($publisher->target())));

        return false;
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
        ConfigPublishers $publishers,
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
            $this->reportPrefixes($survey, $empty, $publishers);
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
    private function reportPrefixes(RouteSurvey $survey, array $documents, ConfigPublishers $publishers): void
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
        $settings = $publishers->all()[0] ?? null;
        $this->line(sprintf(
            '<fg=gray>Set documents.%s.routes.include in %s — e.g. [\'%s\'].</>',
            TerminalText::of($documents[0]),
            $settings === null ? ConfigFile::NAME : $this->projectPath($settings->target()),
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
        if ((app(BuildConfig::class)->engine()['mode'] ?? null) === TypeEngineMode::Null->value) {
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
