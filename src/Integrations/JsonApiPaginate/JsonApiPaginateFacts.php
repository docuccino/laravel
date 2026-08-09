<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

/**
 * The `jsonPaginate()` facts the parameters extension builds from the shared
 * {@see PaginationTerminalVisitor} trace: whether the chain
 * reaches the terminal at all, and the per-call-site overrides the macro accepts
 * (`jsonPaginate(?maxResults, ?defaultSize)`), folded from the outermost call's int args. A small DTO
 * the parameters extension populates once the trace returns and hands to {@see JsonApiPaginateParameters}.
 */
final class JsonApiPaginateFacts
{
    public bool $paginates = false;

    /** The macro's first argument (`$maxResults`) when folded from a literal at the call site. */
    public ?int $maxResultsOverride = null;

    /** The macro's second argument (`$defaultSize`) when folded from a literal at the call site. */
    public ?int $defaultSizeOverride = null;
}
