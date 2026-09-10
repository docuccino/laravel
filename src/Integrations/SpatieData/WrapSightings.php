<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Reads laravel-data's unwrapping vocabulary out of a class's own method bodies and says which
 * {@see WrapReason}s stand. It only ever reads; {@see WrapResolver} composes the answer.
 *
 * The whole job is naming the RECEIVER, because both spellings are receiver-decided: spatie puts
 * `withoutWrapping()` on collections and on a transformation-context factory exactly as it puts it on
 * a data object, and a `WrapExecutionType` rides the transformation it is handed and no other. So a
 * sighting is attributed to the nearest enclosing call whose receiver can be NAMED — `$this`, or a
 * value the object holds — and a factory chain in between (`TransformationContextFactory::create()
 * ->withWrapExecutionType(…)`) is transparent, passing the outer attribution through rather than
 * claiming the sighting for itself.
 *
 * @internal
 */
final class WrapSightings
{
    /** Spatie's transformation-level wrapping switch, matched post-NameResolver so an alias can't hide it. */
    public const WRAP_EXECUTION_TYPE = 'Spatie\\LaravelData\\Support\\Wrapping\\WrapExecutionType';

    /**
     * The reasons that stand for this class, keyed by case name so nothing can read an order into the
     * answer — what {@see WrapResolver} asks is which claims are supported and how far.
     *
     * @param  array<string, ClassMethod>  $methods  the class's OWN declared methods
     * @return array<string, WrapReason>
     */
    public static function standing(array $methods): array
    {
        $standing = [];

        foreach (WrapReason::cases() as $reason) {
            if (self::stands($reason, $methods)) {
                $standing[$reason->name] = $reason;
            }
        }

        return $standing;
    }

    /**
     * Whether one reason stands over these bodies.
     *
     * @param  array<string, ClassMethod>  $methods
     */
    private static function stands(WrapReason $reason, array $methods): bool
    {
        return match ($reason) {
            WrapReason::SelfWithoutWrapping => in_array(true, self::withoutWrappingReceivers($methods), true),
            WrapReason::SelfTransformDisabled => in_array(true, self::disablingReceivers($methods), true),
            WrapReason::UnattributedDisabling => in_array(null, self::withoutWrappingReceivers($methods), true)
                || in_array(null, self::disablingReceivers($methods), true),
        };
    }

    /**
     * Who each `withoutWrapping()` call in these bodies was made on.
     *
     * @param  array<string, ClassMethod>  $methods
     * @return list<bool|null>
     */
    private static function withoutWrappingReceivers(array $methods): array
    {
        $receivers = [];

        foreach ($methods as $method) {
            foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], MethodCall::class) as $call) {
                if ($call->name instanceof Identifier && $call->name->toString() === 'withoutWrapping') {
                    $receivers[] = self::receiver($call->var);
                }
            }
        }

        return $receivers;
    }

    /**
     * Who each `WrapExecutionType::Disabled` in these bodies was handed to.
     *
     * @param  array<string, ClassMethod>  $methods
     * @return list<bool|null>
     */
    private static function disablingReceivers(array $methods): array
    {
        $receivers = [];

        foreach ($methods as $method) {
            $body = $method->stmts ?? [];
            self::attribute($body, null, $receivers, self::carriers($body));
        }

        return $receivers;
    }

    /**
     * The locals a disabled transformation context was assigned to, which is how it reads once a
     * second option is set on it. Every USE of such a local is the sighting; the assignment is not,
     * so {@see attribute()} steps over it.
     *
     * @param  array<Node>  $body
     * @return list<string>
     */
    private static function carriers(array $body): array
    {
        $names = [];

        foreach ((new NodeFinder)->findInstanceOf($body, Assign::class) as $assign) {
            $target = $assign->var;

            if ($target instanceof Variable && is_string($target->name) && self::isCarrier($assign)) {
                $names[] = $target->name;
            }
        }

        return array_values(array_unique($names));
    }

    /** Whether an assignment puts a disabled transformation context into a plainly named local. */
    private static function isCarrier(Assign $assign): bool
    {
        return $assign->var instanceof Variable
            && is_string($assign->var->name)
            && (new NodeFinder)->findFirst([$assign->expr], self::isDisabled(...)) !== null;
    }

    /**
     * Walk a subtree recording who each `Disabled` fetch inside it is attributed to. A call whose
     * receiver can be named claims its arguments; one whose receiver cannot (a static call, a `new`,
     * a local) is a builder as often as it is a subject, so it passes the outer attribution down
     * untouched and leaves the sighting unattributed if there was none.
     *
     * @param  list<bool|null>  $receivers
     * @param  list<string>  $carriers
     */
    private static function attribute(mixed $node, ?bool $consumer, array &$receivers, array $carriers): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                self::attribute($child, $consumer, $receivers, $carriers);
            }

            return;
        }

        if (! $node instanceof Node) {
            return;
        }

        if (self::isDisabled($node)
            || ($node instanceof Variable && is_string($node->name) && in_array($node->name, $carriers, true))) {
            $receivers[] = $consumer;

            return;
        }

        if ($node instanceof Assign && self::isCarrier($node)) {
            return;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            self::attribute($node->var, $consumer, $receivers, $carriers);
            self::attribute($node->name, $consumer, $receivers, $carriers);
            self::attribute($node->args, self::receiver($node->var) ?? $consumer, $receivers, $carriers);

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            self::attribute($node->$name, $consumer, $receivers, $carriers);
        }
    }

    /** Whether a node is the `WrapExecutionType::Disabled` fetch itself. */
    private static function isDisabled(Node $node): bool
    {
        return $node instanceof ClassConstFetch
            && $node->class instanceof Name
            && $node->class->toString() === self::WRAP_EXECUTION_TYPE
            && $node->name instanceof Identifier
            && $node->name->toString() === 'Disabled';
    }

    /**
     * Who a receiver chain names: true for `$this` plus method hops only, false for a value the object
     * HOLDS — a property, an array element — and null where nothing can be named, which is a local, a
     * static call or a `new`.
     *
     * False and null are the two answers no reason is raised for, and they must stay apart all the
     * same: a held value settles nothing and is owed no report, while an unnameable one is doubt about
     * the root and is owed one. Null is not "somebody else", it is "no answer".
     */
    private static function receiver(Expr $expr): ?bool
    {
        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof Variable && $expr->name === 'this') {
            return true;
        }

        $held = $expr instanceof PropertyFetch
            || $expr instanceof NullsafePropertyFetch
            || $expr instanceof StaticPropertyFetch
            || $expr instanceof ArrayDimFetch;

        return $held ? false : null;
    }
}
