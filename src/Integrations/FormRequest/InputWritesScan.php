<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;

/**
 * Everything in a `prepareForValidation()` body, other than its unconditional merges, that writes the
 * request's input: which literal keys a further merge names, and whether anything writes keys that cannot
 * be named at all ({@see $opaque}). {@see CopiedInputs} trusts a copied key only when nothing here touches it.
 *
 * `$this` is safe only as the receiver of a call or a read. Handed anywhere else — an argument, an
 * assignment, an array — it reaches code that may `merge()` over the copy, and so does one of its bags.
 */
final class InputWritesScan extends NodeVisitorAbstract
{
    /** Methods on the request that write its input wholesale, whatever they are passed. */
    private const INPUT_WRITERS = ['replace', 'offsetSet', 'offsetUnset', 'setJson', 'initialize'];

    /** Methods on one of the request's bags that write it. */
    private const BAG_WRITERS = ['set', 'add', 'remove', 'replace'];

    /** Request methods that hand back one of its input bags, which the caller can then write. */
    private const BAG_GETTERS = ['json', 'getInputSource'];

    /** The facade, by its class and by the global alias the framework registers for it. */
    private const REQUEST_FACADES = ['Illuminate\\Support\\Facades\\Request', 'Request'];

    /** What the container resolves to the current request: its key and the classes the framework aliases to it. */
    private const CONTAINER_REQUEST = ['request', 'Illuminate\\Http\\Request', 'Symfony\\Component\\HttpFoundation\\Request'];

    /** Something writes, or may skip writing, keys this cannot name. */
    public bool $opaque = false;

    /**
     * Literal key → how many merges beyond the unconditional ones name it.
     *
     * @var array<string, int>
     */
    public array $writes = [];

    private int $closures = 0;

    /** @var list<Node> the ancestors of the node being entered, innermost last */
    private array $ancestors = [];

    /**
     * @param  ReflectionClass<object>  $class
     * @param  array<int, true>  $unconditional  the object ids of the merge calls already read as statements
     */
    public function __construct(
        private readonly ReflectionClass $class,
        private readonly array $unconditional,
    ) {}

    public function enterNode(Node $node): null
    {
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->closures++;
        }

        // An early return skips every merge after it; a closure's own return does not.
        if ($node instanceof Node\Stmt\Return_ && $this->closures === 0) {
            $this->opaque = true;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef || $node instanceof Node\Stmt\Unset_) {
            foreach ($node instanceof Node\Stmt\Unset_ ? $node->vars : [$node->var] as $target) {
                if (($target instanceof Expr\ArrayDimFetch || $target instanceof Expr\PropertyFetch) && CopiedInputs::rootsAtThis($target->var)) {
                    $this->opaque = true;
                }
            }
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name) {
            $class = ltrim($node->class->toString(), '\\');
            if (in_array(strtolower($class), ['parent', 'self', 'static'], true)) {
                $this->ownMethod($node->name);
            } elseif (in_array($class, self::REQUEST_FACADES, true)) {
                $this->otherHandle($node->name);
            }
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->methodCall($node);
        }

        if ($node instanceof Expr\Variable && CopiedInputs::isThis($node)) {
            $this->follow($node);
        }

        $this->ancestors[] = $node;

        return null;
    }

    public function leaveNode(Node $node): null
    {
        array_pop($this->ancestors);

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->closures--;
        }

        return null;
    }

    private function methodCall(MethodCall|NullsafeMethodCall $call): void
    {
        $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        // The container's request is the one the FormRequest was built from, which shares its JSON bag.
        if (self::isContainerRequest($call->var)) {
            $this->otherHandle($call->name);

            return;
        }

        if (! CopiedInputs::isThis($call->var)) {
            if ($name !== null && in_array($name, self::BAG_WRITERS, true) && CopiedInputs::rootsAtThis($call->var)) {
                $this->opaque = true;
            }

            return;
        }

        if ($name === null || in_array($name, self::INPUT_WRITERS, true) || CopiedInputs::isApplicationMethod($this->class, $name)) {
            $this->opaque = true;

            return;
        }

        if (! in_array($name, ['merge', 'mergeIfMissing'], true) || isset($this->unconditional[spl_object_id($call)])) {
            return;
        }

        $items = $call instanceof MethodCall ? CopiedInputs::literalItems($call) : null;
        if ($items === null) {
            $this->opaque = true;

            return;
        }

        foreach (array_keys($items) as $key) {
            $this->writes[$key] = ($this->writes[$key] ?? 0) + 1;
        }
    }

    /**
     * The container's request, however it is asked for: `request()`, or `app()`, `resolve()` or a
     * container's `make()` given `'request'` or a class the container aliases to it.
     */
    private static function isContainerRequest(Expr $expr): bool
    {
        if ($expr instanceof MethodCall
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'make'
            && self::isContainer($expr->var)
        ) {
            return self::namesRequest($expr->args);
        }

        if (! $expr instanceof Expr\FuncCall) {
            return false;
        }

        if (self::functionNamed($expr, 'request')) {
            return $expr->args === [];
        }

        return (self::functionNamed($expr, 'app') || self::functionNamed($expr, 'resolve')) && self::namesRequest($expr->args);
    }

    /** `app()`, or the container a FormRequest is handed as `$this->container`. */
    private static function isContainer(Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall) {
            return self::functionNamed($expr, 'app') && $expr->args === [];
        }

        return $expr instanceof Expr\PropertyFetch
            && CopiedInputs::isThis($expr->var)
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'container';
    }

    private static function functionNamed(Expr $expr, string $name): bool
    {
        return $expr instanceof Expr\FuncCall
            && $expr->name instanceof Node\Name
            && strtolower(ltrim($expr->name->toString(), '\\')) === $name;
    }

    /**
     * Whether a container lookup's arguments are just `'request'` or a class aliased to it.
     *
     * @param  array<Node>  $args  every argument kind a parser version yields
     */
    private static function namesRequest(array $args): bool
    {
        $only = count($args) === 1 && $args[0] instanceof Node\Arg && ! $args[0]->unpack ? $args[0]->value : null;

        if ($only instanceof Node\Scalar\String_) {
            return in_array(ltrim($only->value, '\\'), self::CONTAINER_REQUEST, true);
        }

        return $only instanceof Expr\ClassConstFetch
            && $only->class instanceof Node\Name
            && $only->name instanceof Node\Identifier
            && strtolower($only->name->toString()) === 'class'
            && in_array(ltrim($only->class->toString(), '\\'), self::CONTAINER_REQUEST, true);
    }

    /** `static::`, `self::`, `parent::` or `$this::` naming a method of the class's own code, which may write anything. */
    private function ownMethod(Node $name): void
    {
        if (! $name instanceof Node\Identifier || CopiedInputs::isApplicationMethod($this->class, $name->toString())) {
            $this->opaque = true;
        }
    }

    /** A call on another handle to the same input — the container's request, the facade — that may write it. */
    private function otherHandle(Node $name): void
    {
        if (! $name instanceof Node\Identifier || in_array($name->toString(), [...self::INPUT_WRITERS, ...self::BAG_GETTERS, 'merge', 'mergeIfMissing'], true)) {
            $this->opaque = true;
        }
    }

    /**
     * Follows `$this` out through the request's own objects — a declared property such as a bag, or a
     * method that hands a bag back — to the expression that uses it. A call on it, a read of an input
     * value (`$this->title`), an offset read, a class-name use and `isset()` keep it here; anything else
     * hands it on.
     */
    private function follow(Expr\Variable $request): void
    {
        $child = $request;
        for ($i = count($this->ancestors) - 1; $i >= 0; $i--) {
            $parent = $this->ancestors[$i];

            if (($parent instanceof Expr\PropertyFetch || $parent instanceof Expr\NullsafePropertyFetch) && $parent->var === $child) {
                if (! $parent->name instanceof Node\Identifier) {
                    $this->opaque = true;

                    return;
                }

                // An undeclared property is `__get()`, which answers an input value, not an object.
                if (! $this->class->hasProperty($parent->name->toString())) {
                    return;
                }

                $child = $parent;

                continue;
            }

            if (($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall) && $parent->var === $child) {
                if ($child === $request && $parent->name instanceof Node\Identifier && in_array($parent->name->toString(), self::BAG_GETTERS, true)) {
                    $child = $parent;

                    continue;
                }

                return;
            }

            if ($parent instanceof Expr\StaticCall && $parent->class === $child) {
                if ($child === $request) {
                    $this->ownMethod($parent->name);
                } else {
                    $this->opaque = true;
                }

                return;
            }

            if (($parent instanceof Expr\ArrayDimFetch && $parent->var === $child)
                || ($parent instanceof Expr\ClassConstFetch && $parent->class === $child)
                || ($parent instanceof Expr\Instanceof_ && $parent->expr === $child)
                || $parent instanceof Expr\Isset_
                || $parent instanceof Expr\Empty_
            ) {
                return;
            }

            $this->opaque = true;

            return;
        }
    }
}
