<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use DateTimeInterface;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * The formats laravel-data READS a Data class's date property in, found the way its `CastPropertiesDataPipe`
 * finds the cast: the property's own, else the `data.casts` entry for the date's class, its parents, then
 * its interfaces. A transformer is never consulted, as it only writes output ({@see WrittenDate}).
 *
 * Only laravel-data's own `DateTimeInterfaceCast` is read: it tries its `format`, else `data.date_format`,
 * a pattern or a list of them, and accepts a value any one of them parses. Any other cast may read anything,
 * and with no cast, or none that can build the declared class, nothing reads the value at all, so neither
 * is guessed at.
 */
final class ReadDate
{
    public const CAST = 'Spatie\\LaravelData\\Casts\\DateTimeInterfaceCast';

    /** laravel-data's shipped `data.casts` entry for dates, so an unconfigured app documents what it reads. */
    public const DEFAULT_CASTS = [DateTimeInterface::class => self::CAST];

    /** @var non-empty-list<string> */
    private readonly array $dateFormats;

    /**
     * @param  string|list<string>  $dateFormat  the app's `data.date_format`
     * @param  array<string, string>  $casts  the app's `data.casts`
     */
    public function __construct(
        private readonly DataClassReflector $reflector,
        string|array $dateFormat = DateWireFormat::DEFAULT_FORMAT,
        private readonly array $casts = self::DEFAULT_CASTS,
    ) {
        $this->dateFormats = self::formats($dateFormat) ?? [DateWireFormat::DEFAULT_FORMAT];
    }

    /**
     * The patterns a date of `$dateClass` held by this property is accepted in, each as the bytes it reads
     * are written, or null where a cast no one can read reads it, or none does.
     *
     * @return non-empty-list<string>|null
     */
    public function patterns(string $fqcn, string $property, string $dateClass): ?array
    {
        $cast = $this->reflector->propertyCast($fqcn, $property) ?? $this->configured($dateClass);
        if ($cast === null || $cast['class'] !== self::CAST) {
            return null;
        }

        // The cast builds the value with `createFromFormat()` on its `type`, else the declared class, so an
        // interface such as `DateTimeInterface` is read by no format at all.
        $type = $cast['arguments']['type'] ?? $cast['arguments'][2] ?? $dateClass;
        if (! is_string($type) || ! class_exists($type) || ! method_exists($type, 'createFromFormat')) {
            return null;
        }

        $format = $cast['arguments']['format'] ?? $cast['arguments'][1] ?? null;
        $formats = $format === null ? $this->dateFormats : self::formats($format);

        return $formats === null ? null : array_map(DateWireFormat::readPattern(...), $formats);
    }

    /**
     * The `data.casts` entry laravel-data meets first for a value of this class: keyed by the class itself,
     * then by each parent, then by each interface, exactly as written.
     *
     * @return array{class: string, arguments: array<array-key, mixed>}|null
     */
    private function configured(string $dateClass): ?array
    {
        foreach (array_unique([$dateClass, ...array_values(class_parents($dateClass) ?: []), ...array_values(class_implements($dateClass) ?: [])]) as $type) {
            if (isset($this->casts[$type])) {
                return ['class' => ltrim($this->casts[$type], '\\'), 'arguments' => []];
            }
        }

        return null;
    }

    /**
     * A configured format as the list laravel-data tries, or null where it holds none.
     *
     * @return non-empty-list<string>|null
     */
    private static function formats(mixed $format): ?array
    {
        $formats = array_values(array_filter(is_array($format) ? $format : [$format], static fn (mixed $entry): bool => is_string($entry) && $entry !== ''));

        return $formats === [] ? null : $formats;
    }
}
