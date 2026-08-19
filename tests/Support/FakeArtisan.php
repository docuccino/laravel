<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

/**
 * A throwaway script standing in for an application's `artisan`, so a real subprocess can prove what
 * reached it: its argv, its environment, and whether the colour it wrote survived the trip back.
 */
final class FakeArtisan
{
    private function __construct(public readonly string $path) {}

    /**
     * Reports its arguments in green, pauses, then reports the fragment-cache variable — two writes
     * far enough apart that a reader can tell streaming from buffering.
     */
    public static function reporting(int $exit = 0): self
    {
        return self::of(<<<PHP
            <?php
            fwrite(STDOUT, "\\033[32margv:".implode('|', array_slice(\$argv, 1))."\\033[0m\\n");
            usleep(250000);
            fwrite(STDOUT, 'cache='.var_export(getenv('DOCUCCINO_FRAGMENT_CACHE'), true)."\\n");
            exit({$exit});
            PHP);
    }

    /** Never finishes, so the only way out is the caller's timeout. */
    public static function hanging(): self
    {
        return self::of("<?php\nsleep(120);\n");
    }

    public function remove(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
    }

    private static function of(string $source): self
    {
        $directory = sys_get_temp_dir().'/docuccino-artisan-'.uniqid('', true);
        mkdir($directory, 0755, true);

        $path = $directory.'/artisan';
        file_put_contents($path, $source);

        return new self($path);
    }
}
