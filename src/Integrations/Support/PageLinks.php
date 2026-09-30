<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;

/**
 * Which page links a paginated collection sends, decided by the `paginationInformation()` it inherits —
 * the method Laravel builds the envelope's `links` through. Every page builder takes one, so no caller
 * can fall back to Laravel's links by leaving it out.
 */
enum PageLinks
{
    /** Laravel's own: every link of the kind, null where there is no such page. */
    case Laravel;

    /** timacdonald/json-api's: the null links dropped, so only those there is a page for. */
    case Available;

    /** The links `$collection` sends, read from the class its `paginationInformation()` is declared by. */
    public static function of(ClassT $collection): self
    {
        return JsonApiTopLevel::declaring($collection->fqcn, 'paginationInformation') === TimacdonaldResourceReflector::JSON_API_COLLECTION
            ? self::Available
            : self::Laravel;
    }
}
