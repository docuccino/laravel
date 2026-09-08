<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\Return_;
use ReflectionMethod;

/**
 * What a gate method's body proves about whether it can deny — the one question every producer of the
 * implicit 403 asks of a `return true`-shaped gate, whether the gate is a FormRequest's `authorize()`
 * or the policy method behind a `can:` middleware.
 *
 * Three-valued because {@see Unread} is not one answer: the producers want opposite defaults for it and
 * both are right, so each states its own at its own call site rather than having one baked in here.
 * The three rows, stated here because nothing inside a single producer could say they differ:
 *
 *  - `ImplicitResponsesExtension`'s FormRequest arm takes the engine's answer alone ({@see analysed()})
 *    and reads {@see Unread} as a gate that never refuses, so no 403 publishes. It decides whether to
 *    PUBLISH an error, and an error nothing can throw is the cost of being wrong.
 *  - The same extension's `can:` path takes both reads ({@see read()}) and reads {@see Unread} as a
 *    gate that can refuse, so nothing is reported. It decides whether to REPORT on an error already
 *    published, and an author invited to hide a real one is the cost of being wrong.
 *  - `ActionAuthorizeResponsesExtension` does not read the body at all and publishes the 403 whenever
 *    an `authorize()` exists — a question left unasked on purpose, rather than answered a third way.
 *
 * `docs/design/defect-classes.md` §"A partition that covers everything and agrees on nothing" is the
 * class this was an instance of.
 */
enum GateBody
{
    /** Provably a literal `return true;` — nothing this body does can deny. */
    case AlwaysAllows;

    /** At least one thing it returns is something else, so a request can be refused. */
    case CanDeny;

    /** Nobody could read the body. Not "it allows" and not "it denies". */
    case Unread;

    /**
     * The ENGINE's answer and nothing else: it follows several return statements and folds a branch,
     * which no source read does, and its dependency files reach the fragment cache on the way past. A
     * build with no analyser installed answers {@see Unread} for every body.
     */
    public static function analysed(RouteContext $context, ReflectionMethod $method, string $file): self
    {
        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(
            new ActionRef($file, $method->getDeclaringClass()->getName(), $method->getName(), $line === false ? 0 : $line),
        );
        $context->recordDependencyFiles($analysis->dependencyFiles);

        if ($analysis->returns === []) {
            return self::Unread;
        }

        foreach ($analysis->returns as $return) {
            if (! ($return->type instanceof LiteralT && $return->type->value === true)) {
                return self::CanDeny;
            }
        }

        return self::AlwaysAllows;
    }

    /**
     * Both readers, for a caller whose question has to be answerable without an analyser installed — the
     * engine is a dev-only package, and a check that quietly stopped working without it would be a
     * feature only the machine that builds the docs has.
     *
     * The narrow read goes first, and not because it is better: where it answers {@see AlwaysAllows} it
     * is CERTAIN — one statement, and it is a literal `return true;` — so nothing a wider reader says can
     * overrule it, and a failed analysis cannot silently turn the answer into "it can deny" (the engine
     * reports a failure AS a return of unknown type, which is indistinguishable from a body that really
     * does return something else). Everything the narrow read cannot see is the engine's: several return
     * statements, a branch that folds.
     */
    public static function read(RouteContext $context, ReflectionMethod $method, string $file): self
    {
        $parsed = self::parsed($method, $file);
        if ($parsed === self::AlwaysAllows) {
            return self::AlwaysAllows;
        }

        $analysed = self::analysed($context, $method, $file);

        return $analysed === self::Unread ? $parsed : $analysed;
    }

    /**
     * The narrow read: one statement, and it is a literal `return true;`. Anything arriving from a call,
     * a variable or a condition is {@see CanDeny}; a file that will not parse, or a node nothing ties to
     * this method, is {@see Unread}.
     */
    private static function parsed(ReflectionMethod $method, string $file): self
    {
        $node = ParsedClassFile::methods($file)[$method->getName()] ?? null;
        // One file can hold several classes and traits declaring one method name, and the parse keys by
        // name alone. The line is what ties a node to the method reflection found.
        if ($node === null || $node->getStartLine() !== $method->getStartLine()) {
            return self::Unread;
        }

        $statements = $node->stmts;
        if ($statements === null || count($statements) !== 1) {
            return self::CanDeny;
        }

        $statement = $statements[0];
        if (! $statement instanceof Return_ || ! $statement->expr instanceof ConstFetch) {
            return self::CanDeny;
        }

        return strtolower($statement->expr->name->toString()) === 'true' ? self::AlwaysAllows : self::CanDeny;
    }
}
