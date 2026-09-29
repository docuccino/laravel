<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Laravel\Support\LaravelActionHooks;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;

/**
 * Everything in the body that runs before validation, other than its unconditional merges, that writes the
 * request's input: which literal keys a further merge names, and whether anything writes keys that cannot
 * be named at all ({@see $opaque}). {@see CopiedInputs} trusts a copied key only when nothing here touches it.
 *
 * The request ({@see InputHandle}) is safe only as the receiver of a call or a read. Handed anywhere else —
 * an argument, an assignment, an array — it reaches code that may `merge()` over the copy, and so does one
 * of its bags. Where `$this` is not the request, the object it is may hold one, so it is held to the same.
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

    /**
     * What the container resolves to a request sharing the validated input: its key, the classes the framework
     * aliases to it, and the request laravel-actions binds for the action it validates.
     */
    private const CONTAINER_REQUEST = ['request', 'Illuminate\\Http\\Request', 'Symfony\\Component\\HttpFoundation\\Request', LaravelActionHooks::ACTION_REQUEST];

    /** Functions that read or write a variable by its name, which a body can reach the request by. */
    private const NAMED_VARIABLE_ACCESS = ['compact', 'extract', 'get_defined_vars'];

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
     * @param  array<int, true>  $unconditional  the object ids of the merge calls already read as statements
     */
    public function __construct(
        private readonly InputHandle $handle,
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
                if (($target instanceof Expr\ArrayDimFetch || $target instanceof Expr\PropertyFetch) && $this->handle->rootsAt($target->var)) {
                    $this->opaque = true;
                }
            }
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name) {
            $class = ltrim($node->class->toString(), '\\');
            if (in_array(strtolower($class), ['parent', 'self', 'static'], true)) {
                $this->ownMethod($this->handle->owner, $node->name);
            } elseif (in_array($class, self::REQUEST_FACADES, true)) {
                $this->otherHandle($node->name);
            }
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->methodCall($node);
        }

        if ($node instanceof Expr\Variable) {
            $this->variable($node);
        }

        // A request variable can be reached by its name as well as by itself.
        if (! $this->handle->isSelf() && $node instanceof Expr\FuncCall) {
            foreach (self::NAMED_VARIABLE_ACCESS as $function) {
                if (self::functionNamed($node, $function)) {
                    $this->opaque = true;
                }
            }
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
        if ($this->isContainerRequest($call->var)) {
            $this->otherHandle($call->name);

            return;
        }

        if (! $this->handle->is($call->var)) {
            if ($name !== null && in_array($name, self::BAG_WRITERS, true) && $this->handle->rootsAt($call->var)) {
                $this->opaque = true;
            }

            // An object that is not the request may hold one: its own code, or a writer called on what it holds.
            if (! $this->handle->isSelf() && InputHandle::isThis(InputHandle::root($call->var))) {
                if (InputHandle::isThis($call->var)) {
                    $this->ownMethod($this->handle->owner, $call->name);
                } elseif ($name !== null && in_array($name, self::BAG_WRITERS, true)) {
                    $this->opaque = true;
                } else {
                    $this->otherHandle($call->name);
                }
            }

            return;
        }

        if ($name === null || in_array($name, self::INPUT_WRITERS, true) || CopiedInputs::isApplicationMethod($this->handle->request, $name)) {
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
    private function isContainerRequest(Expr $expr): bool
    {
        if ($expr instanceof MethodCall
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'make'
            && $this->isContainer($expr->var)
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

    /** `app()`, or the container a FormRequest is handed as its `container`. */
    private function isContainer(Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall) {
            return self::functionNamed($expr, 'app') && $expr->args === [];
        }

        return $expr instanceof Expr\PropertyFetch
            && $this->handle->is($expr->var)
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

    /**
     * A call naming a method of the class's own code, which may write anything.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function ownMethod(ReflectionClass $class, Node $name): void
    {
        if (! $name instanceof Node\Identifier || CopiedInputs::isApplicationMethod($class, $name->toString())) {
            $this->opaque = true;
        }
    }

    /** The request followed out through the uses of it; so is `$this` where it is not the request, since it may hold one. */
    private function variable(Expr\Variable $variable): void
    {
        if ($this->handle->is($variable)) {
            $this->follow($variable, $this->handle->request);
        } elseif (! $this->handle->isSelf() && InputHandle::isThis($variable)) {
            $this->follow($variable, $this->handle->owner);
        } elseif (! $this->handle->isSelf() && ! is_string($variable->name)) {
            // `$$name` may be the request variable.
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
     * Follows a variable out through its own objects — a declared property such as a bag, or a method that
     * hands a bag back — to the expression that uses it. A call on it, a read of an input value
     * (`$this->title`), an offset read, a class-name use and `isset()` keep it here; anything else hands it on.
     *
     * @param  ReflectionClass<object>  $class  the variable's class
     */
    private function follow(Expr\Variable $request, ReflectionClass $class): void
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
                if (! $class->hasProperty($parent->name->toString())) {
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
                    $this->ownMethod($class, $parent->name);
                } else {
                    $this->opaque = true;
                }

                return;
            }

            // A write INTO one of its objects: the enter check has already refused it where that object is the
            // request's, so what is left is the state of an object that is not the request.
            if (($parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp) && $parent->var === $child && $child !== $request) {
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
