<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Inference\CallableRef;

/**
 * One entry of the handler's exception map (`$exceptions->map(…)`): the class it is keyed on, and what it
 * translates a match to: the class a `map(From::class, To::class)` names outright ({@see $target}), or where
 * the mapper closure is written ({@see $at}). {@see $label} is what an entry is reported as.
 */
final readonly class ExceptionMapping
{
    public function __construct(
        public string $from,
        public string $label,
        public ?string $target = null,
        public ?LocatedCallable $at = null,
        public ?string $parameterName = null,
    ) {}

    /** Whether a throw of `$thrownFqcn` is translated by this entry — the `is_a()` `mapException()` asks. */
    public function matches(string $thrownFqcn): bool
    {
        return $thrownFqcn === $this->from || is_a($thrownFqcn, $this->from, true);
    }

    /**
     * The mapper analysed for one thrown class — every return it can reach with its parameter narrowed to that
     * class, each read as the exception it builds. Null for a class-string entry, which names its answer, and
     * for a mapper with no source to read.
     */
    public function ref(string $thrownFqcn): ?CallableRef
    {
        return $this->at?->ref($this->parameterName, $thrownFqcn, returnsExceptions: true);
    }
}
