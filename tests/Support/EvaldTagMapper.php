<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

/**
 * A `tags.mapper` no file holds, for the rows about what the cache does when it cannot key one.
 *
 * `eval()`'d code reports a file like `/path/Test.php(12) : eval()'d code`, which is a path no
 * `is_file()` matches — and a dependency manifest records a file that isn't there as ABSENT, which reads
 * FRESH for as long as it stays absent. So there is nothing here to key a fragment on.
 */
final class EvaldTagMapper
{
    /** The mapper's FQCN. Declared by {@see ensure()} and by nothing else. */
    public const string MAPPER = 'Docuccino\\Laravel\\Tests\\Temp\\EvaldTagMapper';

    /** Declare it, if this process hasn't already — one process may declare a class once. */
    public static function ensure(): string
    {
        if (! class_exists(self::MAPPER, false)) {
            eval('namespace Docuccino\Laravel\Tests\Temp; class EvaldTagMapper implements \Docuccino\Core\Extensions\Contracts\TagMapper { public function map(string $tag): string { return "Evald: ".$tag; } }');
        }

        return self::MAPPER;
    }
}
