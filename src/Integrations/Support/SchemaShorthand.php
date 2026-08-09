<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * Tiny JSON-schema shorthands shared by the pagination/Data envelope builders — an object with
 * properties, and the nullable scalar types the paginator link/meta shapes are built from
 * (`['string', 'null']` / `['integer', 'null']`). The envelope classes themselves stay deliberately
 * distinct (each cross-references the other as NOT interchangeable); only these leaf builders are one.
 */
final class SchemaShorthand
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nullableString(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nullableInteger(): array
    {
        return ['type' => ['integer', 'null']];
    }
}
