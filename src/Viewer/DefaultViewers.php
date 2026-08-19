<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * The drivers that ship with the package, in the order they are offered to a document. The first is
 * the default: what `viewer.driver` resolves to when it is unset, and what an unknown name degrades
 * to.
 *
 * @internal
 */
final class DefaultViewers
{
    /** The `viewer.driver` value a document gets when it names none. */
    public const string DEFAULT = 'scalar';

    /**
     * Class-strings, container-resolved through {@see ExtensionRegistry} exactly like the build-time
     * extensions, so a driver registered with `Docuccino::extend()` sits beside these rather than in a
     * table of its own.
     *
     * @return list<class-string>
     */
    public static function all(): array
    {
        return [
            ScalarViewer::class,
            RedocViewer::class,
        ];
    }
}
