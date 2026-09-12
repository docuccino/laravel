<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;

/**
 * The filters this route recovered that the integration could not type
 * ({@see QueryBuilderParameters::typesNothing()}), each against the query parameter it was PUBLISHED
 * under — so {@see QueryBuilderUntypedFilterExtension}'s end-of-build report is addressed at the node
 * the parameters pass wrote, never at one re-derived from config. A {@see RouteContext::notes()} channel
 * because the two are separate passes, and it rides the operation fragment so a warm hit still reports.
 */
final class UntypedFilters
{
    private const string CHANNEL = 'query-builder.untyped-filter';

    /**
     * @param  string  $parameter  the query parameter $filter's value was published under
     */
    public static function record(RouteContext $context, string $parameter, string $filter): void
    {
        $context->notes()->record(self::CHANNEL, $parameter, $filter);
    }

    /**
     * Query parameter ⇒ the filters published under it, sorted by {@see RouteNotes::all()} so the report
     * never depends on the order the chain declared them.
     *
     * @return array<string, list<string>>
     */
    public static function recorded(RouteContext $context): array
    {
        return $context->notes()->all()[self::CHANNEL] ?? [];
    }
}
