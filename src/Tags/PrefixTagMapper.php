<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tags;

use Docuccino\Core\Extensions\Contracts\TagMapper;

/**
 * The built-in {@see TagMapper}: maps a raw tag through the `tags.map` config. An exact key match
 * wins; failing that, the first configured key the tag *starts with* wins (so `admin.users` →
 * "Admin" from a single `'admin.' => 'Admin'` entry); otherwise the tag passes through unchanged.
 * Prefix keys are tried in declared order for determinism.
 */
final readonly class PrefixTagMapper implements TagMapper
{
    /**
     * @param  array<string, string>  $map
     */
    public function __construct(
        private array $map = [],
    ) {}

    public function map(string $tag): string
    {
        if (array_key_exists($tag, $this->map)) {
            return $this->map[$tag];
        }

        foreach ($this->map as $prefix => $mapped) {
            if ($prefix !== '' && str_starts_with($tag, $prefix)) {
                return $mapped;
            }
        }

        return $tag;
    }
}
