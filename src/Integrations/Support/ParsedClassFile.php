<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Parse a PHP source file and expose its class-method AST nodes — the one home for the
 * "read file → parse → run NameResolver → collect ClassMethod nodes" boilerplate the Eloquent
 * accessor/cast readers and the Query-Builder custom-filter reader each hand-rolled. Names are
 * resolved to FQCNs (so an `Attribute::make` call matches whatever alias the file imported it under).
 * Every failure mode — unreadable file, parse error, unexpected shape — yields an empty map rather
 * than an exception, so a caller simply degrades to its own fallback.
 *
 * {@see methods()} reads the whole FILE. A caller asking a question about one class wants
 * {@see methodsOf()} instead: a file may declare more than one class, and a sibling's body is not
 * evidence about its neighbour.
 */
final class ParsedClassFile
{
    /**
     * The file's class-method nodes keyed by method name (last definition wins — method names are
     * unique within a class), or `[]` when the file cannot be read or parsed.
     *
     * @return array<string, ClassMethod>
     */
    public static function methods(string $file): array
    {
        $ast = self::parse($file);

        if ($ast === null) {
            return [];
        }

        $nodes = [];
        foreach ((new NodeFinder)->findInstanceOf($ast, ClassMethod::class) as $method) {
            $nodes[$method->name->toString()] = $method;
        }

        return $nodes;
    }

    /**
     * The methods one class DECLARES, keyed by method name — {@see methods()} narrowed to the
     * class-like node whose resolved name is `$fqcn`, so a second class sharing the file contributes
     * nothing. `[]` when the file cannot be parsed or declares no such class.
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
