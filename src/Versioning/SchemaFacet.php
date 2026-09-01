<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * Which of a class's published shapes a version verb names. One class can produce two — a Data class
 * is a request body on the way in and a response on the way out — and the document identifies them
 * separately, so a verb that resolved the wrong one would rewrite a schema the author never named.
 *
 * The mint is {@see SchemaIdentity::publishedId()}, which is also what the recovery chain wrote the
 * ids with. Asking it here rather than spelling `#request` again is the whole point: a reader with its
 * own copy of the qualifier finds nothing the day the producer's copy moves, and reports that the
 * document publishes no such schema.
 *
 * @internal
 */
enum SchemaFacet
{
    case Response;

    case Request;

    /** The identity of the node this facet of `$fqcn` is published under. */
    public function identityOf(string $fqcn, IdentityGenerator $identity): string
    {
        return $identity->namedSchemaId(SchemaIdentity::publishedId(
            ltrim($fqcn, '\\'),
            $this === self::Request ? 'request' : '',
        ));
    }

    /** How a diagnostic names the half of the wire this facet is. */
    public function noun(): string
    {
        return $this === self::Request ? 'request' : 'response';
    }
}
