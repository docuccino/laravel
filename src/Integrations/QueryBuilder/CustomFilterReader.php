<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
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
 * Always returns the files the class's declaration spans so they join the fragment-cache dependency set —
 * editing the filter class, or the parent or trait its `__invoke` comes from, re-documents the endpoint.
 * Reflection/parse failures degrade to no facts, and keep the files.
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

        $reflection = new ReflectionClass($fqcn);
        // Outside the try: a class this fails to read is still one the build read, and a warm build
        // has to re-read it once it is fixed.
        $files = DeclarationFiles::forClass($reflection);

        try {
            $attribute = $this->attribute($reflection);
            if ($attribute !== null) {
                return new CustomFilterFacts(files: $files, attribute: $attribute);
            }

            return new CustomFilterFacts(files: $files, column: $this->invokeColumn($reflection));
        } catch (Throwable) {
            return new CustomFilterFacts(files: $files);
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

    /**
     * The column the `__invoke` PHP calls on the class filters on, parsed from wherever it is written.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function invokeColumn(ReflectionClass $reflection): ?string
    {
        $method = $reflection->hasMethod('__invoke') ? ParsedClassFile::declarationOf($reflection->getMethod('__invoke')) : null;

        return $method === null ? null : $this->whereColumns->fromInvoke($method);
    }
}
