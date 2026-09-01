<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * What a verb may ask about the document it is rewriting, while it is rewriting it.
 *
 * Two questions, and the second is why this exists at all. A verb that re-introduces a field has to
 * publish a shape for it, and the only shape it can publish without a converter is a pointer at
 * something the document ALREADY carries — so it needs to turn a class name into the component name
 * this build happened to publish it under. That answer is a function of the whole document, which a
 * verb handed one node cannot see.
 *
 * The mint is {@see SchemaFacet}'s, so a class that pinned its own identity with `#[SchemaId]` resolves
 * here exactly as it does everywhere else.
 *
 * @internal
 */
final readonly class PublishedSchemas
{
    /** The bucket a class's schema is published in, and the only one a re-added field may point at. */
    private const string SECTION = 'schemas';

    /**
     * @param  array<string, mixed>  $doc
     */
    public function __construct(
        private array $doc,
        private IdentityGenerator $identity = new IdentityGenerator,
    ) {}

    /**
     * The `$ref` this document publishes `$fqcn`'s shape at, or null where it publishes none. Matched
     * by IDENTITY rather than by the component's name: a name is minted from the class's short name and
     * from whatever else contested it, so comparing names would miss a schema the document plainly
     * carries and find one it does not.
     */
    public function refFor(string $fqcn, SchemaFacet $facet): ?string
    {
        $id = $facet->identityOf($fqcn, $this->identity);

        $components = $this->doc['components'] ?? null;
        $schemas = is_array($components) ? ($components[self::SECTION] ?? null) : null;

        if (! is_array($schemas)) {
            return null;
        }

        foreach ($schemas as $name => $body) {
            $docuccino = is_array($body) ? ($body['x-docuccino'] ?? null) : null;

            if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
                return DocumentGraph::componentPointer(self::SECTION, (string) $name);
            }
        }

        return null;
    }

    /**
     * The body of the component a canonical pointer addresses — what the fork's walk expands when it
     * follows a `$ref` on the way down to the schema it is copying.
     *
     * @return array<array-key, mixed>|null
     */
    public function body(string $pointer): ?array
    {
        return DocumentGraph::componentBody($this->doc, $pointer);
    }
}
