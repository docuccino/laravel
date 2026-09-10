<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

/**
 * Why {@see WrapResolver} could not settle a root envelope it nonetheless has to publish something for.
 * Each case is a place the class said something about wrapping that the static read could not finish,
 * so each names a line the author can go and look at — which is the only reason this reaches them as a
 * diagnostic rather than as silence.
 *
 * @internal
 */
enum WrapUncertainty
{
    /** The unwrapping vocabulary is in the class and no receiver could be named for it. */
    case DisablingNotAttributed;

    /** `defaultWrap()` is overridden and does not return a literal, so the key itself is unread. */
    case DefaultWrapNotLiteral;

    /** The clause that says what was seen. */
    public function because(): string
    {
        return match ($this) {
            self::DisablingNotAttributed => 'it disables wrapping somewhere this read could not attribute to a receiver',
            self::DefaultWrapNotLiteral => 'its defaultWrap() override does not return a literal',
        };
    }

    /** What the author can do about it. */
    public function help(): string
    {
        return match ($this) {
            self::DisablingNotAttributed => 'Call it on the receiver directly — `$this->withoutWrapping()`, or hand the disabled transformation context straight to `$this->transform(…)` — so the receiver is readable; or state the response shape in an overlay.',
            self::DefaultWrapNotLiteral => 'Return the key as a string literal from `defaultWrap()`, or state the response shape in an overlay.',
        };
    }
}
