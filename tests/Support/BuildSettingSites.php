<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Config\DeclaredSettings;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every place the suite sets a Docuccino key through the framework's config repository, and which of
 * them name a key the framework no longer reads.
 *
 * A build reads `docuccino.yaml` and nothing else, so `config()->set('docuccino.lint.tags.enabled')`
 * is a silent no-op — the test then asserts against the shipped default and passes for the wrong
 * reason. Two of those have landed already, and only a full-suite run caught either. This is the
 * scanner that fails instead.
 */
final class BuildSettingSites
{
    /**
     * The test files that set a key the framework does not read ON PURPOSE, and why each one does.
     *
     * A file is listed here because it is testing the SPLIT: it plants a stale key to prove the build
     * reports it and does not honour it. Everywhere else, a set through this path is the defect above,
     * and {@see BuildSettings} is how a test configures a build.
     *
     * @var array<string, string>
     */
    public const array DELIBERATE = [
        'Feature/ConfigSplitTest.php' => 'plants build settings in the framework config to prove they are reported and not merged',
        'Feature/DefaultDocumentTest.php' => 'plants build settings with no configuration file, to prove the build refuses rather than inventing a document',
        'Feature/UnreadConfigRefusalTest.php' => 'plants build settings with no configuration file, to prove every command refuses the same way',
        'Feature/InstallCommandTest.php' => 'plants build settings in the framework config, which is what install has to find there and report',
        'Feature/MigrateConfigCommandTest.php' => 'plants build settings in the framework config, which is the input the migration exists to read',
    ];

    /**
     * The two files that hold these calls as DATA rather than making them: this scanner's own
     * definition, and the test that hands it the call it has to refuse. Skipped by name so neither can
     * quietly excuse anything else, and both names are held to naming a real file.
     *
     * @var list<string>
     */
    public const array WRITTEN_OUT = [
        'Support/BuildSettingSites.php',
        'Unit/BuildSettingSeamTest.php',
    ];

    /** Where the scan looks: the adapter's whole suite, which is where every one of these sits. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Every file whose sources set a key the framework does not read, as path => the keys it sets.
     * Paths are relative to {@see root()}, so the answer does not name the machine.
     *
     * @return array<string, list<string>>
     */
    public static function pastTheReader(): array
    {
        $found = [];

        foreach (self::files() as $path => $contents) {
            $offending = self::offending($contents);

            if ($offending !== []) {
                $found[$path] = $offending;
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * Every `config('docuccino.…')` set in the suite, wherever it sits — the scan's denominator. A
     * scanner that stopped recognising its own shape would report no offenders and read as a pass, so
     * the count is asserted beside them.
     *
     * @return list<string>
     */
    public static function every(): array
    {
        $sites = [];

        foreach (self::files() as $contents) {
            foreach (self::sites($contents) as $site) {
                $sites[] = $site[0];
            }
        }

        return $sites;
    }

    /**
     * The keys `$contents` sets that a build will never read: anything the framework's own file does
     * not keep ({@see ConfigSplit::FRAMEWORK_KEYS}).
     *
     * A key written as anything but one whole literal comes back as offending too:
     * `'docuccino.documents.'.$key` cannot be resolved by reading the line, and the conservative
     * direction is the one that asks the file to declare itself rather than the one that passes in
     * silence.
     *
     * @return list<string>
     */
    public static function offending(string $contents): array
    {
        $offending = [];

        foreach (self::sites($contents) as [$path, $whole]) {
            if ($whole && self::framework($path)) {
                continue;
            }

            $offending[] = $path;
        }

        return $offending;
    }

    /**
     * The `docuccino.` keys one file's source sets, as [key, whether the key was one whole literal].
     *
     * Both spellings, because both are in the suite: `config()->set('docuccino.x', …)` and
     * `config(['docuccino.x' => …])`.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function sites(string $contents): array
    {
        $pattern = '/config\((?:\)->set\(|\s*\[)\s*\'docuccino\.([A-Za-z0-9_.*-]*)(\'?)/';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $sites = [];

        foreach ($matches as $match) {
            $sites[] = [$match[1], ($match[2] ?? '') === "'"];
        }

        return $sites;
    }

    /**
     * Whether the framework's own config file still keeps this key, or a bag it sits inside. `documents`
     * on its own does not qualify: only the viewer under a document stayed behind, so a set at the bag
     * itself reaches build settings as well and the line does not say which was meant.
     */
    private static function framework(string $path): bool
    {
        $normal = DeclaredSettings::normalized([$path])[0];

        foreach (ConfigSplit::FRAMEWORK_KEYS as $key) {
            if ($normal === $key || str_starts_with($normal, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The suite's PHP sources, keyed by their path relative to {@see root()}.
     *
     * @return array<string, string>
     */
    private static function files(): array
    {
        $files = [];
        $root = self::root();

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (in_array($relative, self::WRITTEN_OUT, true)) {
                continue;
            }

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
