<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;

/**
 * Contributes the effective `spatie/laravel-json-api-paginate` config (the renamable page/number/size/
 * cursor parameter names, method name, sizes, and pagination mode) to the environment digest (design
 * §10, A4). Renaming a page parameter or flipping to cursor pagination changes the documented
 * parameters but touches no route file, so it must key the warm-fragment cache. Gated with the
 * JsonApiPaginate integration; the same {@see JsonApiPaginateConfig} the extensions are wired from is
 * injected so the digest tracks the live bag.
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
