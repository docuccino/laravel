<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;

/**
 * Contributes the effective `spatie/laravel-query-builder` config (the renamable filter/sort/include/
 * fields parameter names + strict mode) to the environment digest (design §10, A4). Renaming
 * `query-builder.parameters.filter` changes every documented QB parameter name but touches no route
 * file, so it must key the warm-fragment cache. Gated with the QueryBuilder integration; the same
 * {@see QueryBuilderConfig} the extension is wired from is injected so the digest tracks the live bag.
 *
 * Only the PACKAGE's bag belongs here. `QueryBuilderConfig::$filterDescriptions` comes from Docuccino's
 * own per-document `integrations.query_builder` bag, which `DocumentConfig::hash()` already folds into
 * the `configHash` the fragment key is built from — and this contributor is document-agnostic, so a
 * per-document value could not be read here truthfully anyway.
 */
final class QueryBuilderConfigDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly QueryBuilderConfig $config) {}

    public function digest(): string
    {
        return 'query-builder:'.implode(',', [
            $this->config->filter,
            $this->config->sort,
            $this->config->include,
            $this->config->fields,
            $this->config->strict ? 'strict' : 'lenient',
            // The suffixes shape the documented include enum, so renaming one must re-document.
            $this->config->countSuffix,
            $this->config->existsSuffix,
            // The delimiter decides whether lists carry the comma-array contract at all.
            $this->config->delimiter,
        ]);
    }
}
