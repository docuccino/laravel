<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * How a test configures a build now that a build reads `docuccino.yaml`: by writing YAML, and handing
 * it to the reader the product uses.
 *
 * This exists because there is one seam worth having. A test that reached past the reader and bound a
 * parsed array would prove the adapter works on input nothing validated — a `1.10` that never became a
 * float, a `no` that never became a string, a `documents:` list that was never refused — and every one
 * of those is a defect {@see ConfigFile} exists to catch. So a setting set here becomes YAML text, and
 * that text goes through {@see ConfigFile::parse()}: the same byte-order-mark strip, the same parse
 * flags, the same numeric settling and the same refusals a real project gets. What is NOT exercised
 * here is finding the file — which name it has, where it is, what an absent or unreadable one means —
 * and that half is stated against real files on disk instead, in the reader's own tests and in the
 * adapter's configuration-file tests.
 *
 * The other way in is the framework's config repository, which a build stopped reading and which
 * accepts any key without a word. Nothing here can close that off, so it is not claimed: it is
 * SCANNED, by {@see BuildSettingSites} and the test that executes it, which fails a suite that sets a
 * build setting through `config()` outside the few files whose subject is exactly that.
 *
 * The baseline is the SHIPPED file, parsed. So the ~2000 tests that override one setting are all
 * standing on the bytes an application gets from `docuccino:install`, which is a stronger footing than
 * the framework config they used to inherit — a shipped default that stopped making sense would now
 * fail somewhere rather than only in the file's own test.
 */
final class BuildSettings
{
    /** The shipped file's text, read once per process. */
    private static ?string $shipped = null;

    /**
     * This test's settings, or null before anything asked for them.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $settings = null;

    /** The reader this harness last bound, so a rebuilt container can be handed it again. */
    private static ?BuildConfig $bound = null;

    /**
     * Point `BuildConfig` at the shipped configuration and forget the last test's edits.
     *
     * Called from {@see TestCase::setUp()} after the container exists, so it
     * replaces the provider's binding rather than racing it.
     */
    public static function boot(): void
    {
        self::$settings = null;
        self::$bound = null;
        self::bind();
    }

    /**
     * Set one build setting, addressed the way the YAML nests it — `documents.default.info.title`,
     * `lint.tags.enabled`, `cache.enabled`.
     *
     * The whole file is rewritten and reparsed, because a setting is only worth what the reader makes
     * of it: a test that pushed a PHP value straight into the parsed map would be asserting against
     * something no author can write.
     */
    public static function set(string $path, mixed $value): void
    {
        $settings = self::settings();
        $node = &$settings;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node[$segment] ?? null)) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }

        $node = $value;
        unset($node);

        self::replace($settings);
    }

    /**
     * Replace the whole `documents` bag — a suite that wants its own documents rather than the shipped
     * `default` with one key changed.
     *
     * @param  array<string, mixed>  $documents
     */
    public static function documents(array $documents): void
    {
        self::set('documents', $documents);
    }

    /**
     * Replace every setting with a tree whose only content is `$path`, holding `$value`.
     *
     * For a test about one KEY rather than one document: `$value` lands where the path says, with
     * nothing else written at all, so whatever the build then reports is about that key. A `*` segment
     * becomes `default` under a keyed map ({@see DeclaredSettings::KEYED_MAPS}) and a one-entry list
     * anywhere else, which is what the two kinds of `*` mean.
     */
    public static function only(string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $node = $value;

        for ($index = count($segments) - 1; $index >= 0; $index--) {
            if ($segments[$index] !== '*') {
                $node = [$segments[$index] => $node];

                continue;
            }

            $node = $index > 0 && in_array($segments[$index - 1], DeclaredSettings::KEYED_MAPS, true)
                ? ['default' => $node]
                : [$node];
        }

        /** @var array<string, mixed> $node */
        self::replace($node);
    }

    /**
     * Replace every setting there is, for a test whose subject is the FILE rather than one value.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function replace(array $settings): void
    {
        self::$settings = $settings;
        self::bind();
    }

    /** Leave the build with no configuration file at all, which is a supported state. */
    public static function none(): void
    {
        self::$settings = null;
        self::hold(new BuildConfig(ConfigFile::read(self::emptyDirectory())));
    }

    /** Hand the build this YAML verbatim, for a test about what the file SAYS rather than what it holds. */
    public static function yaml(string $yaml): void
    {
        self::$settings = null;
        self::hold(new BuildConfig(ConfigFile::parse($yaml)));
    }

    /**
     * The settings as they stand — the shipped file's, with whatever this test has changed.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        if (self::$settings === null) {
            /** @var array<string, mixed> $parsed */
            $parsed = Yaml::parse(self::shipped(), ConfigFile::FLAGS) ?? [];
            self::$settings = $parsed;
        }

        return self::$settings;
    }

    /**
     * One document's settings, for a test that reads the bag rather than writing it — off the bound
     * {@see BuildConfig}, which is the same read and the same refusals a build makes. Asked of the
     * settings array instead, it would answer the shipped file for a test that had bound something
     * else entirely ({@see none()}, {@see Yaml()}), and hand a caller a bag no build would have seen.
     *
     * @return array<string, mixed>
     */
    public static function document(string $key = 'default'): array
    {
        return self::bound()->document($key);
    }

    /**
     * One document as the SHIPPED file declares it, whatever this test has bound — read straight out of
     * the bytes rather than through the product, so a test can hold the product to them.
     *
     * @return array<string, mixed>
     */
    public static function shippedDocument(string $key = 'default'): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse(self::shipped(), ConfigFile::FLAGS) ?? [];

        /** @var array<string, mixed> $bag */
        $bag = $parsed['documents'][$key] ?? [];

        return $bag;
    }

    /** The shipped `docuccino.yaml`, as the bytes an install writes. */
    public static function shipped(): string
    {
        return self::$shipped ??= (string) file_get_contents(
            dirname(__DIR__, 2).'/config/'.ConfigFile::NAME,
        );
    }

    /**
     * Bind the current settings, dumped to YAML and read back.
     *
     * The inline depth is deep enough that nothing in a document bag collapses into a flow scalar the
     * parser then has to guess at, and `Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE` is deliberately not set —
     * `{}` and `[]` parse to the same empty array, so the dumper's default already round-trips.
     */
    private static function bind(): void
    {
        self::hold(new BuildConfig(ConfigFile::parse(Yaml::dump(self::settings(), 12, 2))));
    }

    /**
     * Bind `$config` and remember it, so {@see bound()} can put it back.
     */
    private static function hold(BuildConfig $config): void
    {
        self::$bound = $config;
        app()->instance(BuildConfig::class, $config);
    }

    /**
     * The reader this harness bound, re-asserted when something has replaced it.
     *
     * `refreshApplication()` mid-test builds a NEW container, whose `BuildConfig` is the provider's
     * read of the real project root — so a test that refreshes would otherwise build its second
     * document from a configuration it never set, and compare the two as though only the thing under
     * test had moved.
     */
    private static function bound(): BuildConfig
    {
        $held = self::$bound;

        if ($held === null) {
            self::bind();

            /** @var BuildConfig $held */
            $held = self::$bound;
        }

        if (app(BuildConfig::class) !== $held) {
            app()->instance(BuildConfig::class, $held);
        }

        return $held;
    }

    /** A directory this process owns that holds no configuration file, made once and left empty. */
    private static function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir().'/docuccino-no-config-'.getmypid();
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
