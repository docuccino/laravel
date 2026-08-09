<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The semantic facts a {@see QueryBuilderTraceVisitor} recovers from an action's Query-Builder
 * chain (design §Representation policies — facts stay stable in the UIR regardless of how a policy
 * later expresses them): the allow-lists (filters/sorts/includes/fields), the documented default
 * sort, whether the chain reaches a paginating terminal (and with what per-page/kind), and any
 * expressions that could not be constant-folded (surfaced as diagnostics, never silently dropped).
 *
 * A mutable accumulator: the visitor writes into it as it walks and the parameters extension reads
 * it back once the trace returns.
 */
final class QueryBuilderFacts
{
    /**
     * The subject model FQCN recovered from `QueryBuilder::for(Article::class)` /
     * `QueryBuilder::for(Article::query())`, or null when it could not be resolved (the exact-filter
     * cast lookup then degrades gracefully — every filter stays a plain string, as before).
     */
    public ?string $subjectModel = null;

    /** @var list<QbEntry> */
    public array $filters = [];

    /** @var list<QbEntry> */
    public array $sorts = [];

    /** @var list<QbEntry> */
    public array $includes = [];

    /** @var list<QbEntry> field entries whose name is a `type.field` path (sparse fieldsets) */
    public array $fields = [];

    /** @var list<string> */
    public array $defaultSorts = [];

    public bool $paginates = false;

    public ?int $perPage = null;

    /** length | simple | cursor — the outermost terminal's paginator kind. */
    public ?string $paginationKind = null;

    /** @var list<string> human descriptions of expressions the trace could not resolve */
    public array $unresolved = [];

    /**
     * True when nothing documentable was recovered — the extension then contributes no parameters.
     */
    public function isEmpty(): bool
    {
        return $this->filters === []
            && $this->sorts === []
            && $this->includes === []
            && $this->fields === []
            && ! $this->paginates;
    }
}
