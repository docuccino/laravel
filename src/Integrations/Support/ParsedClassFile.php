<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\MethodDeclaration;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * Parse a PHP source file and expose its class-method AST nodes — the one home for the
 * "read file → parse → run NameResolver → collect ClassMethod nodes" boilerplate the Eloquent
 * accessor/cast readers and the Query-Builder custom-filter reader each hand-rolled. Names are
 * resolved to FQCNs (so an `Attribute::make` call matches whatever alias the file imported it under).
 * Every failure mode — unreadable file, parse error, unexpected shape — yields an empty map rather
 * than an exception, so a caller simply degrades to its own fallback.
 *
 * {@see methodsOf()} reads one class's own declarations, for a question about everything a class writes.
 * A question about one METHOD goes to {@see declarationOf()}: a name alone answers for a sibling class, and
 * misses a parent's body or a trait's under an alias.
 */
final class ParsedClassFile
{
    /**
     * The methods one class DECLARES, keyed by method name — the class-like node whose resolved name is
     * `$fqcn`, so a second class sharing the file contributes nothing. `[]` when the file cannot be
     * parsed or declares no such class.
     *
     * @return array<string, ClassMethod>
     */
    public static function methodsOf(string $file, string $fqcn): array
    {
        $ast = self::parse($file);

        if ($ast === null) {
            return [];
        }

        foreach ((new NodeFinder)->findInstanceOf($ast, ClassLike::class) as $class) {
            if ($class->namespacedName?->toString() !== ltrim($fqcn, '\\')) {
                continue;
            }

            $nodes = [];
            foreach ($class->getMethods() as $method) {
                $nodes[$method->name->toString()] = $method;
            }

            return $nodes;
        }

        return [];
    }

    /**
     * The node PHP runs for a reflected method — in the file reflection names, followed through trait
     * aliases and `insteadof` ({@see MethodDeclaration}) — or null where it cannot be told. What a caller
     * wants whenever it asks about one method: the maps above key by name, which neither of those reach.
     *
     * @param  array<Node>|null  $statements  the method's file, already parsed, when the caller has it
     */
    public static function declarationOf(ReflectionMethod $method, ?array $statements = null): ?ClassMethod
    {
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }

        return MethodDeclaration::in($statements ?? self::statements($file), $method);
    }

    /**
     * The file's name-resolved statements, or `[]` when it cannot be read or parsed.
     *
     * @return array<Node>
     */
    public static function statements(string $file): array
    {
        return self::parse($file) ?? [];
    }

    /**
     * The file's name-resolved statements, or null when it cannot be read or parsed.
     *
     * @return array<Node>|null
     */
    private static function parse(string $file): ?array
    {
        try {
            $code = file_get_contents($file);
            if ($code === false) {
                return null;
            }

            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
            if ($ast === null) {
                return null;
            }

            return (new NodeTraverser(new NameResolver))->traverse($ast);
        } catch (Throwable) {
            return null;
        }
    }
}
