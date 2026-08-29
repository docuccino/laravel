<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

/**
 * `FormData::$title` used to be called `name`, and the rename shipped in 2026-09-01. Callers pinned
 * before that still read `name`, so the key goes back on the way out — recursively, because the shape
 * turns up nested and inside collections, not only as a bare object.
 */
final class TitleReplacesName implements ApiChange
{
    public function since(): string
    {
        return '2026-09-01';
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function downgrade(array $body): array
    {
        $renamed = [];

        // Renaming in place keeps list keys as list keys and leaves every other key where it was.
        foreach ($body as $key => $value) {
            $renamed[$key === 'title' ? 'name' : $key] = is_array($value) ? $this->downgrade($value) : $value;
        }

        return $renamed;
    }
}
