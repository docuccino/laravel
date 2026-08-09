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
 * Resolves the response-envelope wrap key `spatie/laravel-data` applies to a Data object
 * (docs: as-a-resource/wrapping). A wrapped response nests the payload under a key —
 * `{ "data": <payload> }` for the default `'data'`.
 *
 * Precedence mirrors spatie's `ContextableData`/`Wrap`:
 *
 * 1. a class-level `defaultWrap()` override (`WrapType::Defined`) — its literal string return, read
 *    statically off the class file (never invoked); the base `Data` class does NOT define it, so
 *    `method_exists` true means a real override;
 * 2. else the global `config('data.wrap')` (`WrapType::UseGlobal`), injected by the service provider
 *    (the integration stays vendor-import-free, mirroring the Passport runtime facts);
 * 3. else unwrapped (`null`).
 *
 * The key applies at the RESPONSE ROOT only ({@see DataSchema} guards on depth) — a nested Data
 * property is never wrapped, so a shared component `$ref` stays wrap-free.
 */
final class WrapResolver
{
    public function __construct(private readonly ?string $globalWrap = null) {}

    /**
     * The wrap key for a Data class response, or null when unwrapped. Pass null for a collection (no
     * single owning class), which resolves to the global key only.
     */
    public function key(?string $fqcn): ?string
    {
        return ($fqcn !== null ? $this->defaultWrap($fqcn) : null) ?? $this->globalWrap;
    }

    /** The literal string returned by a class's overridden `defaultWrap()`, or null when none/dynamic. */
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

    /** The named method's AST node in a file, or null when the file is unparseable / lacks it. */
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

    /** The first `return '<literal>';` string of a method body, or null when the return is dynamic. */
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
