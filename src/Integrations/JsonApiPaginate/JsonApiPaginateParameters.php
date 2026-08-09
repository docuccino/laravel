<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Turns a {@see JsonApiPaginateConfig} and the recovered {@see JsonApiPaginateFacts} into the JSON:API
 * pagination query parameters the package resolves — the bracketed `page[number]`/`page[size]` pair
 * (length or simple mode), or `page[cursor]`/`page[size]` under cursor mode. Reuses the Query-Builder
 * integration's {@see QueryParameterSpec} value object (a plain, assertable in-query/optional spec).
 * Pure and deterministic so every config/override combination is dataset-testable without a pipeline.
 */
final class JsonApiPaginateParameters
{
    /**
     * @return list<QueryParameterSpec>
     */
    public function build(JsonApiPaginateConfig $config, JsonApiPaginateFacts $facts): array
    {
        if (! $facts->paginates) {
            return [];
        }

        // Call-site arguments (jsonPaginate($maxResults, $defaultSize)) override config.
        $defaultSize = $facts->defaultSizeOverride ?? $config->defaultSize;
        $maxResults = $facts->maxResultsOverride ?? $config->maxResults;

        $size = new QueryParameterSpec(
            name: $config->bracket($config->sizeParameter),
            schema: ['type' => 'integer', 'default' => $defaultSize, 'minimum' => 1, 'maximum' => $maxResults],
            description: 'Number of results per page.',
        );

        if ($config->mode === JsonApiPaginateConfig::MODE_CURSOR) {
            return [
                new QueryParameterSpec(
                    name: $config->bracket($config->cursorParameter),
                    schema: ['type' => 'string'],
                    description: 'Opaque cursor for the next/previous page.',
                ),
                $size,
            ];
        }

        return [
            new QueryParameterSpec(
                name: $config->bracket($config->numberParameter),
                schema: ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                description: 'Page number.',
            ),
            $size,
        ];
    }
}
