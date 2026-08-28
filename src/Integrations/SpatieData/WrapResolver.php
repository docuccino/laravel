<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Resolves the wrap key spatie nests a response payload under — `{ "data": <payload> }` by default.
 * Precedence mirrors spatie's `ContextableData`/`Wrap`: an explicit `withoutWrapping()` in the class beats
 * everything, then a class-level `defaultWrap()` override, then the global `config('data.wrap')` injected by
 * the service provider, else unwrapped.
 *
 * Both class-level reads are static AST reads over method bodies, never invoked — the class's own file for
 * the unwrapping scan, and whichever file *declares* `defaultWrap()` for the key, which is the trait's file
 * when the override arrives through one. The base `Data` class doesn't define `defaultWrap()`, so
 * `method_exists` being true already means a real override. Answers are memoised per FQCN, since a document
 * asks for the same class once per operation that returns it. {@see DataSchema} applies the key at the
 * response root only — deliberately, since a nested Data property publishes a shared `$ref` that must
 * not carry one caller's envelope. Spatie itself does wrap a nested COLLECTION, which is a divergence
 * {@see NestedCollectionWrap} reports rather than one this class resolves.
 */
final class WrapResolver
{
    /** Spatie's transformation-level wrapping switch, matched post-NameResolver so an alias can't hide it. */
    private const WRAP_EXECUTION_TYPE = 'Spatie\\LaravelData\\Support\\Wrapping\\WrapExecutionType';

    /** @var array<string, string|null> FQCN → resolved wrap key */
    private array $keys = [];

    /** @var array<string, array<string, ClassMethod>> file → its class-method nodes */
    private array $parsed = [];

    public function __construct(private readonly ?string $globalWrap = null) {}

    /**
     * The global `config('data.wrap')` alone, ignoring any class override.
     *
     * This is the key spatie puts a NESTED collection under: it resolves that envelope from the global
     * config, so an item class's own `defaultWrap()` does not change it. {@see key()} is the root's
     * question and answers the class first.
     */
    public function globalKey(): ?string
    {
        return $this->globalWrap;
    }

    /**
     * The wrap key, or null when unwrapped. Pass null for a collection — it has no single owning class,
     * so only the global key applies.
     */
    public function key(?string $fqcn): ?string
    {
        if ($fqcn === null) {
            return $this->globalWrap;
        }

        if (! array_key_exists($fqcn, $this->keys)) {
            $this->keys[$fqcn] = $this->resolve($fqcn);
        }

        return $this->keys[$fqcn];
    }

    private function resolve(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return $this->globalWrap;
        }

        $file = (new ReflectionClass($fqcn))->getFileName();

        if ($file !== false && self::disablesWrapping($this->methods($file))) {
            return null;
        }

        return $this->defaultWrap($fqcn) ?? $this->globalWrap;
    }

    /**
     * Whether the class renders ITSELF through `withoutWrapping()`. A class that strips the envelope on its
     * way to a response is unwrapped however `config('data.wrap')` is set — documenting the global key over
     * the top would describe a body the class explicitly removes. The canonical case is an RFC 9457 problem
     * document: it has to sit at the root, so a globally-wrapped app calls `withoutWrapping()` for it.
     *
     * The receiver decides it. Spatie puts `withoutWrapping()` on paginated collections and on
     * `TransformationContextFactory` too, so a class unwrapping a NESTED collection
     * (`$this->items->withoutWrapping()`) says nothing about its own root — only a call chained straight off
     * `$this` does.
     *
     * @param  array<string, ClassMethod>  $methods
     */
    private static function disablesWrapping(array $methods): bool
    {
        foreach ($methods as $method) {
            $body = $method->stmts ?? [];

            foreach ((new NodeFinder)->findInstanceOf($body, MethodCall::class) as $call) {
                if ($call->name instanceof Identifier
                    && $call->name->toString() === 'withoutWrapping'
                    && self::rootedInThis($call->var)) {
                    return true;
                }
            }

            // The other spelling, for a class that builds its own response and disables wrapping on the
            // transformation instead: `withWrapExecutionType(WrapExecutionType::Disabled)`.
            foreach ((new NodeFinder)->findInstanceOf($body, ClassConstFetch::class) as $fetch) {
                if ($fetch->class instanceof Name
                    && $fetch->class->toString() === self::WRAP_EXECUTION_TYPE
                    && $fetch->name instanceof Identifier
                    && $fetch->name->toString() === 'Disabled') {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether a receiver chain is `$this` plus method hops only — a property or static hop is somebody else. */
    private static function rootedInThis(Expr $receiver): bool
    {
        while ($receiver instanceof MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Variable && $receiver->name === 'this';
    }

    /** The literal an overridden `defaultWrap()` returns, or null when there's none or it's dynamic. */
    private function defaultWrap(string $fqcn): ?string
    {
        if (! method_exists($fqcn, 'defaultWrap')) {
            return null;
        }

        $file = (new ReflectionMethod($fqcn, 'defaultWrap'))->getFileName();
        $node = $file === false ? null : ($this->methods($file)['defaultWrap'] ?? null);

        return $node === null ? null : self::literalReturn($node);
    }

    /**
     * @return array<string, ClassMethod>
     */
    private function methods(string $file): array
    {
        return $this->parsed[$file] ??= ParsedClassFile::methods($file);
    }

    /** The first `return '<literal>';` in a body, or null when every return is dynamic. */
    private static function literalReturn(ClassMethod $method): ?string
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof String_) {
                return $return->expr->value;
            }
        }

        return null;
    }
}
