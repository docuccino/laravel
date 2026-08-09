<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

/**
 * What a {@see PaginationTerminalVisitor} trace found: whether the chain reaches `jsonPaginate()` at all, and
 * the per-call-site overrides the macro accepts, folded from the outermost call's int args. Populated by the
 * parameters extension and handed to {@see JsonApiPaginateParameters}.
 */
final class JsonApiPaginateFacts
{
    public bool $paginates = false;

    /** `jsonPaginate($maxResults, …)`, when it folded from a literal. */
    public ?int $maxResultsOverride = null;

    /** `jsonPaginate(…, $defaultSize)`, when it folded from a literal. */
    public ?int $defaultSizeOverride = null;
}
