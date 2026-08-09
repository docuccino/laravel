<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

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
        try {
            $code = file_get_contents($file);
            if ($code === false) {
                return [];
            }

            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
            if ($ast === null) {
                return [];
            }

            $ast = (new NodeTraverser(new NameResolver))->traverse($ast);

            $nodes = [];
            foreach ((new NodeFinder)->findInstanceOf($ast, ClassMethod::class) as $method) {
                $nodes[$method->name->toString()] = $method;
            }

            return $nodes;
        } catch (Throwable) {
            return [];
        }
    }
}
