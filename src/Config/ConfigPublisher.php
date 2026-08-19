<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\Directory;

/**
 * Copies the shipped `config/docuccino.php` into the application — the one write `docuccino:install`
 * performs. Same bytes `vendor:publish --tag=docuccino-config` writes; it is a seam of its own so the
 * command can tell "already there" from "just written" BEFORE it decides anything, which is what
 * makes overwriting an existing file an explicit request rather than a side effect.
 *
 * @internal
 */
final readonly class ConfigPublisher
{
    public function __construct(
        private string $source,
        private string $target,
    ) {}

    /** Where the file goes, absolute. */
    public function target(): string
    {
        return $this->target;
    }

    public function published(): bool
    {
        return is_file($this->target);
    }

    /** False when the file could not be read or written, leaving whatever was already there untouched. */
    public function publish(): bool
    {
        $contents = @file_get_contents($this->source);
        if ($contents === false) {
            return false;
        }

        if (! Directory::ensure(dirname($this->target))) {
            return false;
        }

        return AtomicFile::write($this->target, $contents);
    }
}
