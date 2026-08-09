<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use ReflectionClass;
use Throwable;

/**
 * Reads the two documentable facts a Spatie custom filter class (`AllowedFilter::custom('x', new F)`)
 * can carry, in precedence order:
 *
 *   1. a `#[QueryParameter]` attribute ON THE CLASS — the explicit author override. Its `name` is
 *      ignored (the parameter name is the `AllowedFilter` name) and so is `required` (a filter never
 *      is); `type`, `description`, `default` and `example` apply. Recorded at the integration layer,
 *      so it beats body inference, but a route-level `#[QueryParameter]` still wins.
 *   2. failing that, the single column its `__invoke(Builder $query, $value, …)` body filters on
 *      ({@see WhereColumnAnalyzer}), so the value types off the subject model's cast exactly like a
 *      callback filter.
 *
 * Always returns the class file (when resolvable) so it joins the fragment-cache dependency set —
 * editing the filter class re-documents the endpoint. Reflection/parse failures degrade to no facts.
 */
final class CustomFilterReader
{
    public function __construct(
        private readonly WhereColumnAnalyzer $whereColumns = new WhereColumnAnalyzer,
    ) {}

    public function read(string $fqcn): CustomFilterFacts
    {
        if (! class_exists($fqcn)) {
            return new CustomFilterFacts;
        }

        try {
            $reflection = new ReflectionClass($fqcn);
            $file = $reflection->getFileName();
            $file = $file === false ? null : $file;

            $attribute = $this->attribute($reflection);
            if ($attribute !== null) {
                return new CustomFilterFacts(file: $file, attribute: $attribute);
            }

            return new CustomFilterFacts(file: $file, column: $file === null ? null : $this->invokeColumn($file));
        } catch (Throwable) {
            return new CustomFilterFacts;
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function attribute(ReflectionClass $reflection): ?QueryParameter
    {
        $attributes = $reflection->getAttributes(QueryParameter::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /** The column the class's `__invoke` filters on, by parsing the class file. */
    private function invokeColumn(string $file): ?string
    {
        $method = ParsedClassFile::methods($file)['__invoke'] ?? null;

        return $method === null ? null : $this->whereColumns->fromInvoke($method);
    }
}
