<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Docuccino\Laravel\Support\HeaderNames;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use ReflectionClass;
use WeakMap;

/**
 * The input keys a FormRequest's `prepareForValidation()` overwrites with a value read from another part
 * of the request by a literal name: a header, a query value, a route parameter. `merge()` writes over what
 * the body sent, and a value that was not sent arrives as a present null, so the rules for such a key
 * validate that other value and a body field of the same name does nothing.
 *
 * Only the certain shape counts — an unconditional `$this->merge([...])` statement in the method's own
 * body, a literal key, and a value read with one literal argument. Anything else that could write a key
 * leaves it as it was, and anything that could write ANY key (a call into the application's own methods,
 * a `replace()`, an early return, `$this` handed to other code) leaves them all.
 *
 * Which keys moved is computed ONCE per operation, by the body side ({@see move()}), and the parameter side
 * reads that record ({@see movedFrom()}), so a rule can only reach a parameter by leaving the body.
 *
 * @phpstan-type CopySource array{in: string, name: string}
 * @phpstan-type MovedInput array{in: string, name: string, rules: list<ValidationRule>}
 */
final class CopiedInputs
{
    public const HEADER = 'header';

    public const QUERY = 'query';

    public const PATH = 'path';

    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    private const HOOK = 'prepareForValidation';

    /** Rules no header, query value or route parameter can satisfy as a whole: containers and uploads. */
    private const CONTAINER_RULES = ['array', 'list', 'file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    /**
     * Operation → the copied keys its body gave up, as {@see move()} recorded them.
     *
     * @var WeakMap<OperationDraft, array<string, MovedInput>>|null
     */
    private static ?WeakMap $moved = null;

    /**
     * Input key → where its value is copied from, for every key the hook certainly overwrites.
     *
     * @return array<string, CopySource>
     */
    public function of(RouteContext $context, string $class): array
    {
        if (! class_exists($class) || ! is_a($class, self::FORM_REQUEST, true)) {
            return [];
        }

        // Before any bail: adding the hook, or a base class that declares it, has to invalidate.
        $context->recordDependencyFiles(DeclarationFiles::of($class));

        $reflection = new ReflectionClass($class);

        // The validator reads what `validationData()` returns; an application's own override may not be
        // the merged input at all.
        if (self::isApplicationMethod($reflection, 'validationData') || ! $reflection->hasMethod(self::HOOK)) {
            return [];
        }

        $hook = $reflection->getMethod(self::HOOK);
        $declaring = $hook->getDeclaringClass()->getName();
        $file = $hook->getFileName();
        if ($file === false || ! self::isApplicationMethod($reflection, self::HOOK)) {
            return [];
        }

        $method = ParsedClassFile::methodsOf($file, $declaring)[self::HOOK] ?? null;
        if ($method === null || $method->stmts === null) {
            return [];
        }

        return $this->read($method->stmts, $reflection);
    }

    /**
     * Moves the keys `$class` copies off `$rules` — the rule set the body is about to publish — and records
     * what moved against the operation for {@see movedFrom()}. Answers the rules the body keeps.
     */
    public function move(OperationDraft $operation, RouteContext $context, string $class, RuleSet $rules): RuleSet
    {
        $copied = $this->of($context, $class);

        // A tagged object read off a key that moves is no partition of the body any more, so it is given up
        // first, and the rules that move are the ones the merged object would have given up.
        $rules = $rules->releasing(array_keys(self::movable($rules, $copied)));
        $moved = self::movable($rules, $copied);
        self::$moved ??= new WeakMap;
        self::$moved[$operation] = $moved;

        return $rules->without(array_keys($moved));
    }

    /**
     * The keys {@see move()} took off this operation's body, each with the rules it took along.
     *
     * @return array<string, MovedInput>
     */
    public static function movedFrom(OperationDraft $operation): array
    {
        return self::$moved[$operation] ?? [];
    }

    /**
     * The copied keys a rule set can move off the request body: a plain top-level key the rules name,
     * with no nested field beneath it and no rule only a container or an upload satisfies.
     *
     * @param  array<string, CopySource>  $copied
     * @return array<string, MovedInput>
     */
    public static function movable(RuleSet $rules, array $copied): array
    {
        $moved = [];
        foreach ($copied as $key => $source) {
            $fieldRules = $rules->fields[$key] ?? null;
            if ($fieldRules === null || $fieldRules === [] || strpbrk($key, '.*\\') !== false) {
                continue;
            }

            foreach (array_keys($rules->fields) as $path) {
                if (str_starts_with($path, $key.'.')) {
                    continue 2;
                }
            }

            foreach ($fieldRules as $rule) {
                if (in_array($rule->name, self::CONTAINER_RULES, true)) {
                    continue 2;
                }
            }

            $moved[$key] = [...$source, 'rules' => $fieldRules];
        }

        return $moved;
    }

    /**
     * @param  array<Node\Stmt>  $statements
     * @param  ReflectionClass<object>  $class
     * @return array<string, CopySource>
     */
    private function read(array $statements, ReflectionClass $class): array
    {
        /** @var array<string, CopySource|null> $copied */
        $copied = [];
        $writes = [];
        $unconditional = [];

        foreach ($statements as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? self::mergeOf($statement->expr) : null;
            $items = $call === null ? null : self::literalItems($call);
            if ($call === null || $items === null) {
                continue;
            }

            $unconditional[spl_object_id($call)] = true;
            foreach ($items as $key => $value) {
                $writes[$key] = ($writes[$key] ?? 0) + 1;
                $copied[$key] = self::sourceOf($value);
            }
        }

        $scan = new InputWritesScan($class, $unconditional);
        (new NodeTraverser($scan))->traverse($statements);

        if ($scan->opaque) {
            return [];
        }

        $certain = [];
        foreach ($copied as $key => $source) {
            if ($source !== null && ($writes[$key] ?? 0) === 1 && ! isset($scan->writes[$key])) {
                $certain[$key] = $source;
            }
        }

        ksort($certain, SORT_STRING);

        return $certain;
    }

    /** `$this->merge(<one argument>)` — the only merge whose effect on the validated input is certain. */
    private static function mergeOf(Expr $expr): ?MethodCall
    {
        return $expr instanceof MethodCall
            && self::isThis($expr->var)
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'merge'
            ? $expr
            : null;
    }

    /**
     * The literal-keyed pairs a merge's one array argument writes, or null when it writes keys this cannot
     * name (a spread, a computed key, anything but an array literal).
     *
     * @return array<string, Expr>|null
     */
    public static function literalItems(MethodCall $call): ?array
    {
        $args = $call->isFirstClassCallable() ? [] : $call->getArgs();
        $array = count($args) === 1 && $args[0]->name === null && ! $args[0]->unpack ? $args[0]->value : null;
        if (! $array instanceof Expr\Array_) {
            return null;
        }

        $items = [];
        foreach ($array->items as $item) {
            if (! $item->key instanceof String_ || $item->unpack || $item->byRef) {
                return null;
            }

            $items[$item->key->value] = $item->value;
        }

        return $items;
    }

    /** `$this`, the FormRequest whose input is being merged. */
    public static function isThis(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === 'this';
    }

    /** An expression on `$this` or on something reached from it — a bag, `json()`. */
    public static function rootsAtThis(Expr $expr): bool
    {
        while ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\ArrayDimFetch
        ) {
            $expr = $expr->var;
        }

        return self::isThis($expr);
    }

    /**
     * Whether the class's own code declares a method — the application's, or a package's it extends, but
     * not the framework's request, whose methods write only what their names say.
     *
     * @param  ReflectionClass<object>  $class
     */
    public static function isApplicationMethod(ReflectionClass $class, string $name): bool
    {
        if (! $class->hasMethod($name)) {
            return false;
        }

        $declaring = $class->getMethod($name)->getDeclaringClass()->getName();

        return ! str_starts_with($declaring, 'Illuminate\\') && ! str_starts_with($declaring, 'Symfony\\');
    }

    /**
     * Where a merged value is read from, when it is a read of one named part of the request.
     *
     * @return CopySource|null
     */
    private static function sourceOf(Expr $value): ?array
    {
        if (! $value instanceof MethodCall || ! $value->name instanceof Node\Identifier || $value->isFirstClassCallable()) {
            return null;
        }

        $args = $value->getArgs();
        $name = count($args) === 1 && $args[0]->name === null && ! $args[0]->unpack && $args[0]->value instanceof String_
            ? $args[0]->value->value
            : null;
        if ($name === null || $name === '') {
            return null;
        }

        $method = $value->name->toString();

        // `$this->headers->get('…')`: the bag `header()` itself reads.
        if ($method === 'get'
            && $value->var instanceof Expr\PropertyFetch
            && self::isThis($value->var->var)
            && $value->var->name instanceof Node\Identifier
            && $value->var->name->toString() === 'headers'
        ) {
            return HeaderNames::isToken($name) ? ['in' => self::HEADER, 'name' => $name] : null;
        }

        if (! self::isThis($value->var)) {
            return null;
        }

        return match ($method) {
            'header' => HeaderNames::isToken($name) ? ['in' => self::HEADER, 'name' => $name] : null,
            // A dotted name reads a nested value, which is no one parameter.
            'query' => str_contains($name, '.') ? null : ['in' => self::QUERY, 'name' => $name],
            'route' => ['in' => self::PATH, 'name' => $name],
            default => null,
        };
    }
}
