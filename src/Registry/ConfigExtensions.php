<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * The `docuccino.extensions` list, read the same way wherever it is read — the build and the viewer's
 * driver lookup both merge it in, so a typo there means one warning, not two answers.
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
     * @return array{0: list<class-string|object>, 1: list<Diagnostic>}
     */
    public static function read(): array
    {
        $out = [];
        $diagnostics = [];

        foreach ((array) config('docuccino.extensions', []) as $extension) {
            if (is_object($extension)) {
                $out[] = $extension;

                continue;
            }

            if (is_string($extension) && class_exists($extension)) {
                $out[] = $extension;

                continue;
            }

            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.extension-missing',
                message: is_string($extension)
                    ? sprintf('docuccino.extensions lists "%s", which no autoloadable class defines — it contributed nothing to this document.', $extension)
                    : sprintf('docuccino.extensions holds a %s where a class-string or an extension instance was expected — it contributed nothing to this document.', get_debug_type($extension)),
                help: 'Check the class name and its namespace in config/docuccino.php, and that the class is autoloadable (composer dump-autoload).',
            );
        }

        return [$out, $diagnostics];
    }
}
