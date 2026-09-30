<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * What `json_encode` sends for items whose keys may or may not run 0…n-1: a list of them, or an object of
 * them. The keys a request happens to produce decide, so the schema admits both.
 */
final class EitherKeyed
{
    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function schema(array $items): array
    {
        return ['type' => ['array', 'object'], 'items' => $items, 'additionalProperties' => $items];
    }
}
