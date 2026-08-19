<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\TerminalText;

/**
 * The one line `docuccino:watch` prints before a rebuild: which files moved, project-relative, and a
 * count once there are more than a handful — a branch switch or a `composer install` moves hundreds
 * at once and none of them is worth a line of its own.
 *
 * Paths come out of the filesystem rather than out of this package, so they go through
 * {@see TerminalText} like every other value the CLI lifts out of an application.
 *
 * @internal
 */
final class ChangeSummary
{
    private const int SHOWN = 3;

    /**
     * @param  list<string>  $changed  absolute paths, in the order {@see WatchSet::changed()} gives them
     */
    public static function of(array $changed, string $basePath): string
    {
        $names = array_map(
            static fn (string $file): string => TerminalText::of(Paths::relative($file, $basePath) ?? $file),
            array_slice($changed, 0, self::SHOWN),
        );

        $rest = count($changed) - count($names);
        if ($rest > 0) {
            $names[] = sprintf('and %d more', $rest);
        }

        return sprintf('%s changed; rebuilding…', implode(', ', $names));
    }
}
