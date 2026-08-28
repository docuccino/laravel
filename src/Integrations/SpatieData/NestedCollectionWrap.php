<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\DType;

/**
 * Says when laravel-data will wrap a nested collection that this document describes as a bare array.
 *
 * Spatie unwraps a nested single Data object but re-wraps a nested COLLECTION, so a property typed as
 * a list of Data serialises as `{"data": […]}` under a global `data.wrap` while the schema says
 * `type: array`. The shape is not modelled, because a `#[WithTransformer]` can replace serialisation
 * outright and no static read can see through one — so the divergence is reported to the author
 * instead, which is the only party who can resolve it.
 *
 * Two shapes it does not answer for. A collection reached through an Illuminate `Collection` generic is
 * wrapped by spatie and is not recognised here — a miss, pending a count of how often one is written.
 * And the wrap is read from the class DECLARING the property, so a collection nested under a different
 * root that disables wrapping is still reported; the same class returned directly would be wrapped, so
 * the report is right about the component even where it is loud about one use of it.
 */
final class NestedCollectionWrap
{
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly WrapResolver $wrap = new WrapResolver,
    ) {}

    /**
     * The diagnostic this property earns, or null where nothing will be wrapped or nothing can be said.
     *
     * The nested key is the GLOBAL wrap, never the item class's `defaultWrap()`: spatie resolves a
     * nested collection's envelope from `config('data.wrap')` alone, so an item class overriding the
     * key does not change what lands on the wire.
     */
    public function diagnose(string $fqcn, string $property, DType $clean): ?Diagnostic
    {
        $key = $this->wrap->globalKey();

        // No global wrap, or a class that disables wrapping for its whole transformation — the latter
        // reads `withoutWrapping()` and `WrapExecutionType::Disabled` alike, and propagates to nested
        // values, so nothing here will be wrapped.
        if ($key === null || $this->wrap->key($fqcn) === null) {
            return null;
        }

        $item = $this->reflector->nestedCollectionItem($fqcn, $property, $clean);
        if ($item === null || $this->reflector->isPropertyTransformed($fqcn, $property)) {
            return null;
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'spatie-data.nested-collection-wrap',
            message: sprintf(
                '%s::$%s is a nested collection of %s, which laravel-data serialises as {"%s": [ … ]} because `data.wrap` is set — this document describes it as a bare array.',
                $fqcn,
                $property,
                $item,
                $key,
            ),
            help: 'Unwrap the property with a `#[WithTransformer]` that returns the bare list, so the wire matches the document; or, if the envelope is intended, state the wrapped shape in an overlay. A property carrying any `#[WithTransformer]` is left alone, since a transformer replaces serialisation and its output cannot be read statically.',
        );
    }
}
