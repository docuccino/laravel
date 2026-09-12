<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\DType;

/**
 * Says when laravel-data will wrap a nested collection the read here recovered as a bare array.
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
     * key does not change what lands on the wire. {@see WrapResolver::wrapsNested()} owns the other
     * half — which of spatie's two switches reaches this far.
     */
    public function diagnose(string $fqcn, string $property, DType $clean): ?Diagnostic
    {
        $key = $this->wrap->globalKey();

        // No global wrap, or nothing left to say about the switch that propagates downward, which is
        // the only one that reaches a value nested in here. A class that merely takes its own root
        // envelope off is not one of them: that writes the object's `Wrap`, which spatie reads for
        // the root and nowhere else.
        if ($key === null || ! $this->wrap->wrapsNested($fqcn)) {
            return null;
        }

        $item = $this->reflector->nestedCollectionItem($fqcn, $property, $clean);
        if ($item === null) {
            return null;
        }

        // Two separate reasons for silence, kept apart because one `null` answering both is how this
        // read went wrong before. A property spatie's own factory cannot see is not on the wire at all,
        // so there is no divergence to report: it builds from `ReflectionClass::getProperties()`, while
        // the type handed in here can have come from a class-level `@property` tag. And a property
        // carrying a transformer has a wire shape no static read can predict, so nothing can be said.
        if (! $this->reflector->declaresProperty($fqcn, $property)
            || $this->reflector->isPropertyTransformed($fqcn, $property)
        ) {
            return null;
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'spatie-data.nested-collection-wrap',
            message: sprintf(
                '%s::$%s is a nested collection of %s, which laravel-data serialises as {"%s": [ … ]} because `data.wrap` is set — the schema recovered for the property is a bare array, with no envelope.',
                $fqcn,
                $property,
                $item,
                $key,
            ),
            help: 'Unwrap the property with a `#[WithTransformer]` that returns the bare list, so the wire matches the document; or, if the envelope is intended, state the wrapped shape in an overlay. A property carrying any `#[WithTransformer]` is left alone, since a transformer replaces serialisation and its output cannot be read statically.',
        );
    }
}
