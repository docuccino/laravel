<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\HeaderNames;
use Illuminate\Support\Facades\Facade;
use PhpParser\Node;

/**
 * Finds every request header the traced code reads by a LITERAL name: `header()`/`hasHeader()` on the
 * request (an injected one, a FormRequest's `$this`, `request()`), the same two through the `Request`
 * facade or its default alias, and `get()`/`has()`/`all()` on its `headers` bag. The receiver's type decides, never its name, so
 * a response's `header('X-Foo', …)` setter is never a read.
 *
 * Reads are grouped the way the framework's header bag looks them up — lowercased, `_` read as `-` — so one
 * header read under two spellings is one header. A name that is not a literal is not a read of anything
 * this can name, and is skipped.
 */
final class RequestHeaderReads implements TraceVisitor
{
    /** Request methods whose argument 0 names one header. */
    private const REQUEST_METHODS = ['header', 'hasHeader'];

    /** Methods of the request's `headers` bag whose argument 0 names one header. */
    private const BAG_METHODS = ['get', 'has', 'all'];

    private const REQUEST_FACADE = 'Illuminate\\Support\\Facades\\Request';

    /**
     * Wire spelling → where each read of it was written, grouped by the name's lookup key.
     *
     * @var array<string, array<string, list<SourceLocation>>>
     */
    private array $reads = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
            $name = $this->readName($node, $scope);
            if ($name !== null) {
                $this->reads[HeaderNames::lookupKey($name)][strtr($name, '_', '-')][] = $scope->location($node);
            }

            // Into app code, so a read inside a FormRequest method or a helper the action calls is reached;
            // the engine declines vendor, magic and over-budget descent itself.
            return true;
        }

        return false;
    }

    /**
     * One entry per header, keyed by its lookup key and byte-sorted: the name to publish it under — the
     * byte-smallest spelling, so a function of the set and never of which read came first — every spelling
     * it was read under, and every place a read was written.
     *
     * @return array<string, array{name: string, spellings: list<string>, locations: list<SourceLocation>}>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->reads as $key => $spellings) {
            $names = array_map(strval(...), array_keys($spellings));
            sort($names, SORT_STRING);

            $name = $names[0] ?? null;
            if ($name !== null) {
                $headers[(string) $key] = ['name' => $name, 'spellings' => $names, 'locations' => array_merge(...array_values($spellings))];
            }
        }

        ksort($headers, SORT_STRING);

        return $headers;
    }

    /** The literal header name a call reads, when it is a read of the request's headers at all. */
    private function readName(Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeScope $scope): ?string
    {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $method = $call->name->toString();

        if ($call instanceof Node\Expr\StaticCall) {
            $reads = in_array($method, self::REQUEST_METHODS, true)
                && $call->class instanceof Node\Name
                && self::isRequestFacade(ltrim($call->class->toString(), '\\'));
        } else {
            $reads = in_array($method, self::REQUEST_METHODS, true)
                ? FrameworkClasses::isRequest($call->var, $scope)
                : in_array($method, self::BAG_METHODS, true) && $this->isHeaderBag($call->var, $scope);
        }

        if (! $reads) {
            return null;
        }

        // `header()` with no name hands back every header and names none.
        $args = $call->getArgs();
        $value = isset($args[0]) ? $scope->constantValueOf($args[0]->value) : null;
        if ($value === null || ! $value->isScalar() || ! is_string($value->scalar)) {
            return null;
        }

        return HeaderNames::isToken($value->scalar) ? $value->scalar : null;
    }

    /**
     * The facade by its own name or by a global alias the framework registers for it by default (`\Request`).
     * An alias an application adds itself lives in config no fragment is keyed on, so it is not read.
     */
    private static function isRequestFacade(string $class): bool
    {
        return $class === self::REQUEST_FACADE
            || (! str_contains($class, '\\') && (Facade::defaultAliases()->all()[$class] ?? null) === self::REQUEST_FACADE);
    }

    /** `<request>->headers`: the bag the request's own header accessors read. */
    private function isHeaderBag(Node\Expr $expr, TypeScope $scope): bool
    {
        return $expr instanceof Node\Expr\PropertyFetch
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'headers'
            && FrameworkClasses::isRequest($expr->var, $scope);
    }
}
