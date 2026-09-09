<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;

/**
 * The `extensions` list out of `docuccino.yaml`, read the same way wherever it is read — the build and
 * the viewer's driver lookup both merge it in, so a typo there means one warning, not two answers.
 *
 * @internal
 */
final class ConfigExtensions
{
    /**
     * The usable entries, plus a warning for every entry that contributed nothing.
     *
     * `Foo\Bar::class` still evaluates to the string when the class does not exist, so a typo'd
     * namespace is a silent no-op — the document simply loses whatever that extension does. A warning,
     * not info: the author asked for behaviour the build could not give them.
     *
     * Class-strings and nothing else. A configuration FILE can name a class; it cannot hold a
     * constructed one, and the parser refuses the tag that would pretend otherwise. An extension that
     * has to be built by hand — a closure over test state, a stub with a constructor argument — goes
     * in through `Docuccino::extend()`, which is where an instance has always belonged.
     *
     * @return array{0: list<class-string>, 1: list<Diagnostic>}
     */
    public static function read(): array
    {
        $out = [];
        $diagnostics = [];

        foreach ((array) app(BuildConfig::class)->raw('extensions') as $extension) {
            if (is_string($extension) && class_exists($extension)) {
                $out[] = $extension;

                continue;
            }

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.extension-missing',
                message: is_string($extension)
                    ? sprintf('extensions lists "%s", which no autoloadable class defines — it contributed nothing to this document.', $extension)
                    : sprintf('extensions holds a %s where a class-string was expected — it contributed nothing to this document.', get_debug_type($extension)),
                help: sprintf(
                    'Check the class name and its namespace in %s, and that the class is autoloadable (composer dump-autoload). An extension you have to construct yourself goes in through Docuccino::extend().',
                    ConfigFile::NAME,
                ),
            );
        }

        return [$out, $diagnostics];
    }
}
