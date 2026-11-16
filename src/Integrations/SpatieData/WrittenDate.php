<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use DateTimeInterface;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * What laravel-data WRITES a Data class's date property as, found the way its `TransformedDataResolver`
 * finds the transformer: the property's own, else the first `data.transformers` entry the value is an
 * instance of, else none. A cast is never consulted — it only reads input, so `#[WithCast(…, format: 'U')]`
 * leaves the response a formatted string.
 *
 * Only laravel-data's own `DateTimeInterfaceTransformer` is read: it writes `format`, else the first of
 * `data.date_format`, less a leading `!`. Any other transformer may write anything, so the date is widened
 * rather than guessed. A time zone moves the value's offset and never its shape.
 */
final class WrittenDate
{
    public const TRANSFORMER = 'Spatie\\LaravelData\\Transformers\\DateTimeInterfaceTransformer';

    /** laravel-data's shipped `data.transformers` entry for dates, so an unconfigured app documents what it sends. */
    public const DEFAULT_TRANSFORMERS = [DateTimeInterface::class => self::TRANSFORMER];

    /**
     * @param  string  $dateFormat  the first of the app's `data.date_format`
     * @param  array<string, string>  $transformers  the app's `data.transformers`, in order
     */
    public function __construct(
        private readonly DataClassReflector $reflector,
        private readonly string $dateFormat = DateWireFormat::DEFAULT_FORMAT,
        private readonly array $transformers = self::DEFAULT_TRANSFORMERS,
    ) {}

    /**
     * The schema a date of `$dateClass` held by this property is written as, `[]` where a transformer no
     * one can read writes it, or null where none does and the value goes out as its own JSON form.
     *
     * @return array<string, mixed>|null
     */
    public function schema(string $fqcn, string $property, string $dateClass): ?array
    {
        $transformer = $this->reflector->propertyTransformer($fqcn, $property)
            ?? $this->configured($dateClass);

        if ($transformer === null) {
            return null;
        }

        if ($transformer['class'] !== self::TRANSFORMER) {
            return [];
        }

        $format = $transformer['arguments']['format'] ?? $transformer['arguments'][1] ?? $this->dateFormat;

        return is_string($format) ? DateWireFormat::serializedSchema(ltrim($format, '!')) : [];
    }

    /**
     * The `data.transformers` entry a value of this class meets first. laravel-data matches the value's
     * runtime class; the declared one is the class every such value is.
     *
     * @return array{class: string, arguments: array<array-key, mixed>}|null
     */
    private function configured(string $dateClass): ?array
    {
        foreach ($this->transformers as $transformable => $transformer) {
            if (is_a($dateClass, ltrim($transformable, ' \\'), true)) {
                return ['class' => ltrim($transformer, '\\'), 'arguments' => []];
            }
        }

        return null;
    }
}
