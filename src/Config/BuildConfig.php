<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Config\ConfigValues;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Commands\MemoryLimitOption;
use Docuccino\Laravel\DocuccinoServiceProvider;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;

/**
 * Everything a build reads, out of the tool's own `docuccino.yaml` at the project root.
 *
 * The split with `config/docuccino.php` is not a preference. The framework loads every file in
 * `config/` while it BOOTS, and {@see DocuccinoServiceProvider::packageBooted()}
 * registers the viewer's routes on every one of those boots — so the master switch, each document's
 * viewer wiring and the cache store stay where the framework can read them without parsing a file
 * that an author may be halfway through editing. Everything that shapes a DOCUMENT is read here,
 * once, by a command. {@see ConfigSplit} is what says so when a build key is left in the other file.
 *
 * One instance per build, held as a container singleton, for two reasons that are both correctness
 * rather than economy: {@see ConfigValues} accumulates the refusals it has made and a second reader
 * would start empty, and a build that read the file twice could resolve two different documents from
 * one command.
 *
 * @internal
 */
final class BuildConfig
{
    /**
     * The environment variables that override one setting each, and the only ones there are.
     *
     * A configuration FILE is the wrong home for a value that differs per run: `docuccino:watch` turns
     * the fragment cache on for the builds it drives without editing anybody's file, and an
     * environment switches inference off without a second committed file per environment. Both were
     * `env()` calls in the framework config, and neither survived the move on its own — a lever named
     * in four diagnostics' help text that silently stopped working would make those sentences lies.
     *
     * The list is closed and it is short on purpose. Every entry is a value a RUN has an opinion about;
     * anything else belongs in the file, where it can be read, reviewed and committed. `enabled` is not
     * here because it never left the framework config, which reads its own `env()` at boot.
     */
    public const string ENGINE_MODE = 'DOCUCCINO_ENGINE';

    /** @var array<string, string>  environment variable => the setting it overrides */
    public const array ENV_OVERRIDES = [
        self::ENGINE_MODE => 'engine.mode',
        ArtisanBuildRunner::FRAGMENT_CACHE => 'cache.enabled',
    ];

    public function __construct(private readonly ConfigFile $file) {}

    /** The read itself — its error, and the diagnostics that say what went wrong. */
    public function file(): ConfigFile
    {
        return $this->file;
    }

    /** Whether the file was read and parsed. False for an absent file, which is a legitimate state. */
    public function present(): bool
    {
        return $this->file->ok();
    }

    /**
     * The whole parsed map. For the readers that already own their own refusals — the flag catalogue,
     * the extension list, the engine bag — so one setting is never reported by two of them.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->file->values;
    }

    /**
     * The `documents` bag, keyed by document key. Refused rather than coerced when it holds something
     * else, so `documents:` written as a LIST is a report and not an invented document set.
     *
     * @return array<string, mixed>
     */
    public function documents(): array
    {
        return $this->values()->map('documents')->all();
    }

    /**
     * One nested section as a plain bag, empty when absent — what a reader that does its own type
     * checking wants.
     *
     * @return array<string, mixed>
     */
    public function section(string $path): array
    {
        return $this->values()->map($path)->all();
    }

    /** A settings value exactly as parsed. Null when the key is not written at all. */
    public function raw(string $path): mixed
    {
        return $this->values()->raw($path);
    }

    /** The typed reader, which refuses a value of the wrong type and records why. */
    public function values(): ConfigValues
    {
        return $this->file->values();
    }

    /**
     * Everything wrong with the configuration a build could see: the file itself, every key in it that
     * names no setting, every setting whose type was refused, and the two-file split.
     *
     * Collected here rather than at each reader because a build reports once and the readers run at
     * container binds, inside a viewer request, in a value object — most of them with nowhere to put
     * a report. Ordered file-first: an unparseable file is why every setting under it is missing, and
     * the unknown keys come before the refused types because a key nothing reads has no type to refuse.
     *
     * The file's own keys are asked about here rather than waited for, because almost none of them is
     * read through the typed reader at all — a section is read off a plain bag and a list or a path
     * through a helper that coerces ({@see ConfiguredShapes}). Asking first is what puts their refusals
     * in the list below.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        ConfiguredShapes::read($this);

        return [
            ...$this->file->diagnostics,
            ...UnknownSettings::report($this),
            ...$this->values()->diagnostics(),
            ...ConfigSplit::report($this),
        ];
    }

    /**
     * One document's raw bag, empty when it names none.
     *
     * @return array<string, mixed>
     */
    public function document(string $key): array
    {
        return Hydrate::map($this->documents()[$key] ?? null);
    }

    /**
     * The `engine` bag as the build will actually use it: the file, then `DOCUCCINO_ENGINE`, then
     * `--memory-limit`, each overriding the one before.
     *
     * Four readers ask for this bag — the deferred engine, the fragment-cache fingerprint, the watch
     * set and the build's own report — and they must be handed the same answer, so the levers are
     * applied once here rather than remembered four times. `--memory-limit` lands through
     * {@see MemoryLimitOption} because a ceiling asked for on the command line is a fact about the
     * process, not about the project.
     *
     * @return array<string, mixed>
     */
    public function engine(): array
    {
        $bag = $this->overridden($this->section('engine'), 'mode', self::ENGINE_MODE);

        // The framework reads the word `null` in an environment variable as PHP null, so the one mode
        // whose NAME is "null" cannot arrive from there as the text the enum is keyed by. Four
        // diagnostics tell their reader to set `DOCUCCINO_ENGINE=null`, so the word means the mode it
        // names rather than an intent that got lost on the way. A null answer to a NON-null default
        // only ever comes from a variable that is set, which is what makes this narrow.
        if (env(self::ENGINE_MODE, self::ENGINE_MODE) === null) {
            $bag['mode'] = TypeEngineMode::Null->value;
        }

        $limit = MemoryLimitOption::requested();
        if ($limit !== null) {
            $bag['memory_limit'] = $limit;
        }

        return $bag;
    }

    /**
     * The `cache` bag as the build will actually use it, with `DOCUCCINO_FRAGMENT_CACHE` applied.
     *
     * `cache.store` is deliberately not here: it names a Laravel cache store the viewer reads on the
     * REQUEST path, so it stays in the framework config where a request can reach it without parsing
     * a file.
     *
     * @return array<string, mixed>
     */
    public function cache(): array
    {
        return $this->overridden($this->section('cache'), 'enabled', ArtisanBuildRunner::FRAGMENT_CACHE);
    }

    /**
     * `$bag` with `$key` replaced by `$variable`'s value where the environment sets one.
     *
     * The environment is read with the FILE's value as the fallback, which is what makes an unset
     * variable a no-op and keeps every conversion the framework's own `env()` does — `false`, `null`
     * and a bare word each arrive exactly as they did when this was an `env()` call in the config
     * file. A value that does not convert to what the setting takes is left as it arrived, so the
     * reader that owns the setting refuses it and names it, rather than this one guessing.
     *
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    private function overridden(array $bag, string $key, string $variable): array
    {
        $current = $bag[$key] ?? null;
        $value = env($variable, $current);

        if ($value !== $current) {
            $bag[$key] = $value;
        }

        return $bag;
    }
}
