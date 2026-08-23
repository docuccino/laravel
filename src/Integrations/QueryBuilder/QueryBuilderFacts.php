<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Laravel\Integrations\Support\RequestPageSizeKey;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeReader;

/**
 * The semantic facts a {@see QueryBuilderTraceVisitor} recovers from an action's Query-Builder
 * chain (design §Representation policies — facts stay stable in the UIR regardless of how a policy
 * later expresses them): the allow-lists (filters/sorts/includes/fields), the documented default
 * sort, whether the chain reaches a paginating terminal (and which one, with what arguments), and any
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

    /** length | simple | cursor — the outermost terminal's paginator kind. */
    public ?string $paginationKind = null;

    /** The outermost terminal's method name — a Laravel terminal or a configured custom one. */
    public ?string $paginationTerminal = null;

    /**
     * The outermost terminal call's folded arguments, in the shape `PaginatorPageParameter::forTerminal()`
     * reads — `FoldedArguments` states the whole rule, including the spread that makes a list unindexable.
     *
     * @var array<array-key, string|int|float|bool|null>|null
     */
    public ?array $paginationArgs = [];

    /**
     * The page-size key the trace followed the paginator's size argument back to a request read for, or
     * null where the size came from the call site or the model — see {@see RequestPageSizeReader}.
     */
    public ?RequestPageSizeKey $pageSize = null;

    /** @var list<string> human descriptions of expressions the trace could not resolve */
    public array $unresolved = [];

    /**
     * The allow-lists one of those expressions belonged to, keyed by bucket. Each recovered member of
     * such a list is still true; its SET is not, so a closed enum over it would tell a generated client
     * to reject a value the server accepts.
     *
     * @var array<string, true>
     */
    public array $unresolvedLists = [];

    /** Whether an entry of one allow-list went unresolved, so its recovered values are not the legal set. */
    public function partial(string $bucket): bool
    {
        return isset($this->unresolvedLists[$bucket]);
    }

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
