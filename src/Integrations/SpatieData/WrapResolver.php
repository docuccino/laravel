<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * Resolves the wrap key spatie nests a response payload under — `{ "data": <payload> }` by default.
 * Precedence mirrors spatie's `ContextableData`/`Wrap`: a class-level `defaultWrap()` override, else the
 * global `config('data.wrap')` injected by the service provider, else unwrapped.
 *
 * The override's literal is read statically off the class file, never invoked. The base `Data` class
 * doesn't define `defaultWrap()`, so `method_exists` being true already means a real override.
 * {@see DataSchema} applies the key at the response root only.
 */
final class WrapResolver
{
    public function __construct(private readonly ?string $globalWrap = null) {}

    /**
     * The wrap key, or null when unwrapped. Pass null for a collection — it has no single owning class,
     * so only the global key applies.
     */
    public function key(?string $fqcn): ?string
    {
        return ($fqcn !== null ? $this->defaultWrap($fqcn) : null) ?? $this->globalWrap;
    }

    /** The literal an overridden `defaultWrap()` returns, or null when there's none or it's dynamic. */
    private function defaultWrap(string $fqcn): ?string
    {
        if (! class_exists($fqcn) || ! method_exists($fqcn, 'defaultWrap')) {
            return null;
        }

        try {
            $method = new ReflectionMethod($fqcn, 'defaultWrap');
            $file = $method->getFileName();
            $node = $file === false ? null : $this->methodNode($file, $method->getName());

            return $node === null ? null : $this->literalReturn($node);
        } catch (Throwable) {
            return null;
        }
    }

    /** The named method's AST node, or null when the file is unparseable or lacks it. */
    private function methodNode(string $file, string $name): ?ClassMethod
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return null;
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return null;
        }

        foreach ((new NodeFinder)->findInstanceOf($ast, ClassMethod::class) as $method) {
            if ($method->name->toString() === $name) {
                return $method;
            }
        }

        return null;
    }

    /** The first `return '<literal>';` in a body, or null when every return is dynamic. */
    private function literalReturn(ClassMethod $method): ?string
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof String_) {
                return $return->expr->value;
            }
        }

        return null;
    }
}
