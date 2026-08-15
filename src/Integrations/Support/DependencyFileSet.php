<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * The files a recovery read, in the order it read them and each named once, for its caller to hand to
 * {@see RouteContext::recordDependencyFiles()}. Nulls are dropped, so a reader that found nothing needs
 * no ceremony at the call site.
 */
final class DependencyFileSet
{
    /**
     * @var list<string>
     */
    private array $files = [];

    public function add(?string ...$files): void
    {
        foreach ($files as $file) {
            if ($file !== null && ! in_array($file, $this->files, true)) {
                $this->files[] = $file;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->files;
    }

    public function clear(): void
    {
        $this->files = [];
    }
}
