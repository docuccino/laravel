<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

/**
 * A reading of laravel-data's unwrapping vocabulary in a class's own methods, each declaring which of
 * spatie's two switches it saw and how far that switch reaches. Every match below is exhaustive, so
 * adding a case is a build error until all three of them and {@see WrapSightings::stands()} decide it.
 *
 * A sighting aimed at a value the object merely HOLDS raises no reason at all: it is not the root, and
 * the ordinary serialisation of a held value is governed by the root's transformation rather than by
 * the call that was written. The rule the reasons compose by is stated once, in {@see WrapResolver}.
 *
 * @internal
 */
enum WrapReason
{
    /** `$this->…->withoutWrapping()` — a chain of method hops off the object, so the receiver is the object. */
    case SelfWithoutWrapping;

    /** A `WrapExecutionType::Disabled` handed to a transformation whose receiver is `$this`. */
    case SelfTransformDisabled;

    /**
     * The vocabulary is here and the receiver could not be named — a context built into a local, a
     * `static::from(…)->withoutWrapping()`, a hop through a helper. It might be the root and it might
     * be a property, which is exactly why it settles nothing.
     */
    case UnattributedDisabling;

    /** Whether it takes the envelope off the response ROOT. Only a receiver of `$this` does. */
    public function unwrapsRoot(): bool
    {
        return match ($this) {
            self::SelfWithoutWrapping, self::SelfTransformDisabled => true,
            self::UnattributedDisabling => false,
        };
    }

    /**
     * Whether the switch it read reaches values nested under the root too. `withoutWrapping()` writes
     * the object's own `Wrap`, which spatie reads once, for the root; a `WrapExecutionType` rides the
     * transformation context and gates every level under it.
     */
    public function propagates(): bool
    {
        return match ($this) {
            self::SelfTransformDisabled => true,
            self::SelfWithoutWrapping, self::UnattributedDisabling => false,
        };
    }

    /**
     * Whether it settles anything alone. The two that name their receiver do. The unattributed one
     * does not: it cannot even rule the root out, so it leaves both questions open rather than
     * deciding either.
     */
    public function isConclusive(): bool
    {
        return match ($this) {
            self::SelfWithoutWrapping, self::SelfTransformDisabled => true,
            self::UnattributedDisabling => false,
        };
    }
}
