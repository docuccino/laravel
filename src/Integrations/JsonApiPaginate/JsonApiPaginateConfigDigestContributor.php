<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;

/**
 * Feeds the effective package config into the environment digest (design §10). Renaming a page parameter or
 * flipping to cursor pagination changes the documented parameters while touching no route file, so it has to
 * key the warm-fragment cache. Takes the same {@see JsonApiPaginateConfig} the extensions are wired from, so
 * the digest tracks the live bag.
 */
final class JsonApiPaginateConfigDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly JsonApiPaginateConfig $config) {}

    public function digest(): string
    {
        return 'json-api-paginate:'.implode(',', [
            $this->config->pageParameter,
            $this->config->numberParameter,
            $this->config->sizeParameter,
            $this->config->cursorParameter,
            $this->config->methodName,
            (string) $this->config->defaultSize,
            (string) $this->config->maxResults,
            $this->config->mode,
        ]);
    }
}
