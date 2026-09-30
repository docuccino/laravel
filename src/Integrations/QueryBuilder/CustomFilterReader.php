<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\UnreadableAttribute;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use ReflectionClass;
use Throwable;

/**
 * Reads the two documentable facts a Spatie custom filter class (`AllowedFilter::custom('x', new F)`)
 * can carry, in precedence order:
 *
 *   1. a `#[QueryParameter]` attribute ON THE CLASS — the explicit author override. It needs no `name`
 *      (the parameter name is the `AllowedFilter` name, and a written one is ignored) and its `required`
 *      is ignored too (a filter never is); `type`, `description`, `default` and `example` apply.
 *      Recorded at the integration layer, so it beats body inference, but a route-level
 *      `#[QueryParameter]` still wins.
 *   2. failing that, the single column its `__invoke(Builder $query, $value, …)` body filters on
 *      ({@see WhereColumnAnalyzer}), so the value types off the subject model's cast exactly like a
 *      callback filter.
 *
 * An attribute PHP cannot construct is reported as `attribute.unreadable` and the body is read as if it
 * were not there — what an unannotated class gets, so the answer stays true. Always returns the files the
 * class's declaration spans so they join the fragment-cache dependency set — editing the filter class,
 * or the parent or trait its `__invoke` comes from, re-documents the endpoint. Reflection/parse failures
 * degrade to no facts, and keep the files.
 */
final class CustomFilterReader
{
    public function __construct(
        private readonly WhereColumnAnalyzer $whereColumns = new WhereColumnAnalyzer,
    ) {}

    public function read(string $fqcn, ?string $routeSignature = null): CustomFilterFacts
    {
        if (! class_exists($fqcn)) {
            return new CustomFilterFacts;
        }

        $reflection = new ReflectionClass($fqcn);
        // Outside the try: a class this fails to read is still one the build read, and a warm build
        // has to re-read it once it is fixed.
        $files = DeclarationFiles::forClass($reflection);
        $diagnostics = [];

        try {
            [$attribute, $diagnostics] = $this->attribute($reflection, $routeSignature);
            if ($attribute !== null) {
                return new CustomFilterFacts(files: $files, attribute: $attribute);
            }

            return new CustomFilterFacts(files: $files, column: $this->invokeColumn($reflection), diagnostics: $diagnostics);
        } catch (Throwable) {
            return new CustomFilterFacts(files: $files, diagnostics: $diagnostics);
        }
    }

    /**
     * The class's first `#[QueryParameter]`, and the report when PHP cannot construct it.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return array{0: ?QueryParameter, 1: list<Diagnostic>}
     */
    private function attribute(ReflectionClass $reflection, ?string $routeSignature): array
    {
        [$declared, $diagnostics] = UnreadableAttribute::instantiate(
            array_slice($reflection->getAttributes(QueryParameter::class), 0, 1),
            ClassNames::publishable($reflection->getName()),
            $routeSignature,
        );

        return [$declared[0] ?? null, $diagnostics];
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
