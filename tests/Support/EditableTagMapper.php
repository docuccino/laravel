<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use ReflectionClass;

/**
 * A `tags.mapper` whose declaration file the TEST owns, so a row can edit that file the way an
 * application edits its own mapper — which is what the fragment cache has to notice.
 *
 * One process may declare a class once, so the edit is written in two halves that stand for the one
 * an application makes: the file on disk really does carry the new body, and the already-loaded class
 * reads its prefix from a static this moves to match. Only the file half reaches the cache.
 */
final class EditableTagMapper
{
    /** The mapper's FQCN. Outside the autoloader on purpose: {@see write()} is the only thing that declares it. */
    public const string MAPPER = 'Docuccino\\Laravel\\Tests\\Temp\\EditableTagMapper';

    /**
     * Write the mapper's file with the given prefix, load it if it isn't loaded, and return the file —
     * resolved, since reflection answers the real path and the temp directory may be a symlink.
     */
    public static function write(string $prefix): string
    {
        $file = self::file();
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, self::body($prefix));

        if (! class_exists(self::MAPPER, false)) {
            require $file;
        }

        (new ReflectionClass(self::MAPPER))->setStaticPropertyValue('prefix', $prefix);

        return (string) realpath($file);
    }

    /**
     * Where the file lives. Per process, since the class inside it can only be declared once — two
     * parallel workers each get their own.
     */
    public static function file(): string
    {
        return sys_get_temp_dir().'/docuccino-editable-mapper-'.getmypid().'/EditableTagMapper.php';
    }

    private static function body(string $prefix): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Docuccino\\Laravel\\Tests\\Temp;

            use Docuccino\\Core\\Extensions\\Contracts\\TagMapper;

            final class EditableTagMapper implements TagMapper
            {
                public static string \$prefix = '{$prefix}';

                public function map(string \$tag): string
                {
                    return self::\$prefix.'-'.\$tag;
                }
            }

            PHP;
    }
}
