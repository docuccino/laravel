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
use Docuccino\Laravel\Support\LaravelActionHooks;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use WeakMap;

/**
 * The input keys code run before validation overwrites with a value read from another part of the request
 * by a literal name: a header, a query value, a route parameter. `merge()` writes over what the body sent,
 * and a value that was not sent arrives as a present null, so the rules for such a key validate that other
 * value and a body field of the same name does nothing. Three bodies do it: a FormRequest's
 * `prepareForValidation()` ({@see of()}), a laravel-actions action's ({@see ofAction()}), and a controller
 * action's own statements before an inline `validate()` ({@see ofInline()}).
 *
 * Only the certain shape counts — an unconditional `merge([...])` statement on the request in that body, a
 * literal key, and a value read off the request with one literal argument. Anything else that could write a
 * key leaves it as it was, and anything that could write ANY key (a call into the application's own
 * methods, a `replace()`, an early return, the request handed to other code) leaves them all.
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

    private const HTTP_REQUEST = 'Illuminate\\Http\\Request';

    private const SYMFONY_REQUEST = 'Symfony\\Component\\HttpFoundation\\Request';

    private const HOOK = 'prepareForValidation';

    /**
     * The FormRequest methods that decide what its validator runs over: each step from `validateResolved()`
     * to the validator it builds, what that validator's data is read through, and `validator()`, which the
     * framework asks for in place of its own. An application's override of any may not validate the merged
     * input at all. Read off the installed framework by a test.
     */
    public const FORM_REQUEST_DATA_METHODS = ['all', 'createDefaultValidator', 'getValidatorInstance', 'validateResolved', 'validationData', 'validator'];

    /** The FormRequest hook handed the validator once it is built, which can give it other data. */
    private const WITH_VALIDATOR = 'withValidator';

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

        foreach (self::FORM_REQUEST_DATA_METHODS as $override) {
            if (self::isApplicationMethod($reflection, $override)) {
                return [];
            }
        }

        // The framework hands `withValidator()` the validator as its one argument.
        $validator = $reflection->hasMethod(self::WITH_VALIDATOR) ? ($reflection->getMethod(self::WITH_VALIDATOR)->getParameters()[0] ?? null)?->getName() : null;
        if (! $reflection->hasMethod(self::HOOK) || ! self::keepsValidatorData($reflection, $validator)) {
            return [];
        }

        $method = self::isApplicationMethod($reflection, self::HOOK) ? ParsedClassFile::declarationOf($reflection->getMethod(self::HOOK)) : null;
        if ($method === null || $method->stmts === null) {
            return [];
        }

        return $this->read($method->stmts, InputHandle::self($reflection));
    }

    /**
     * {@see of()} for a laravel-actions action, whose `prepareForValidation()` the package calls through the
     * container: its `ActionRequest` parameter is the very request the package then validates.
     *
     * @return array<string, CopySource>
     */
    public function ofAction(RouteContext $context, ?string $action): array
    {
        if ($action === null || ! class_exists($action) || ! class_exists(LaravelActionHooks::ACTION_REQUEST)) {
            return [];
        }

        $context->recordDependencyFiles(DeclarationFiles::of($action));

        $reflection = new ReflectionClass($action);
        foreach (LaravelActionHooks::VALIDATION_OVERRIDES as $override) {
            if ($reflection->hasMethod($override)) {
                return [];
            }
        }

        // The package resolves `withValidator()`'s parameters through the container, handing the validator to
        // the one named `$validator`.
        if (! $reflection->hasMethod(self::HOOK) || ! self::keepsValidatorData($reflection, 'validator')) {
            return [];
        }

        $hook = $reflection->getMethod(self::HOOK);
        $variable = self::requestParameter($hook, LaravelActionHooks::ACTION_REQUEST);
        $method = ParsedClassFile::declarationOf($hook);
        if ($variable === null || $method === null || $method->stmts === null) {
            return [];
        }

        return $this->read($method->stmts, new InputHandle($variable, new ReflectionClass(LaravelActionHooks::ACTION_REQUEST), $reflection));
    }

    /**
     * {@see of()} for a controller action that validates inline: the statements of its own body up to the
     * one `$request->validate([...])` or `Validator::make($request->all(), [...])`, which reads the input
     * those statements merged into. `$validations` is how many validation calls the rules were harvested
     * from; anything but that one leaves the rules' origin open, and nothing moves.
     *
     * @return array<string, CopySource>
     */
    public function ofInline(RouteContext $context, int $validations): array
    {
        $class = $context->actionRef->class;
        if ($validations !== 1 || $class === null || ! class_exists($class) || ! method_exists($class, $context->actionRef->method)) {
            return [];
        }

        $context->recordDependencyFiles(DeclarationFiles::of($class));

        $action = new ReflectionMethod($class, $context->actionRef->method);
        $variable = self::requestParameter($action, self::HTTP_REQUEST);
        $method = ParsedClassFile::declarationOf($action);
        if ($variable === null || $method === null || $method->stmts === null) {
            return [];
        }

        $handle = new InputHandle($variable, new ReflectionClass(self::HTTP_REQUEST), new ReflectionClass($class));
        foreach ($method->stmts as $index => $statement) {
            if (self::validates($statement, $handle)) {
                // A validator built here and given other data before it runs validates that data instead.
                return self::givesOtherData(array_slice($method->stmts, $index), null)
                    ? []
                    : $this->read(array_slice($method->stmts, 0, $index + 1), $handle);
            }
        }

        return [];
    }

    /**
     * Moves the `$copied` keys off `$rules` — the rule set the body is about to publish — and records what
     * moved against the operation for {@see movedFrom()}. Answers the rules the body keeps.
     *
     * @param  array<string, CopySource>  $copied
     */
    public function move(OperationDraft $operation, array $copied, RuleSet $rules): RuleSet
    {
        // A tagged object read off a key that moves is no partition of the body any more, so it is given up
        // first, and the rules that move are the ones the merged object would have given up.
        $rules = $rules->releasing(array_keys(self::movable($rules, $copied)));
        $moved = self::movable($rules, $copied);
        if ($moved === []) {
            return $rules;
        }

        // Added to, never replaced: a second rule set published on the operation keeps the first one's moves.
        self::$moved ??= new WeakMap;
        self::$moved[$operation] = [...self::$moved[$operation] ?? [], ...$moved];

        // The body may be left with nothing, and the request still validates what moved.
        $operation->declareValidatesInput();

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
     * @return array<string, CopySource>
     */
    private function read(array $statements, InputHandle $handle): array
    {
        /** @var array<string, CopySource|null> $copied */
        $copied = [];
        $writes = [];
        $unconditional = [];

        foreach ($statements as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? self::mergeOf($statement->expr, $handle) : null;
            $items = $call === null ? null : self::literalItems($call);
            if ($call === null || $items === null) {
                continue;
            }

            $unconditional[spl_object_id($call)] = true;
            foreach ($items as $key => $value) {
                $writes[$key] = ($writes[$key] ?? 0) + 1;
                $copied[$key] = self::sourceOf($value, $handle);
            }
        }

        $scan = new InputWritesScan($handle, $unconditional);
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

    /** `$request->merge(<one argument>)` — the only merge whose effect on the validated input is certain. */
    private static function mergeOf(Expr $expr, InputHandle $handle): ?MethodCall
    {
        return $expr instanceof MethodCall
            && $handle->is($expr->var)
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

    /**
     * The name of the method's one request parameter, when its type is exactly `$class` — the one the
     * framework hands it. A second request parameter is another handle on the input, so there is no answer.
     */
    private static function requestParameter(ReflectionMethod $method, string $class): ?string
    {
        $found = null;
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $names = $type instanceof ReflectionNamedType ? [$type->getName()] : array_map(
                static fn (ReflectionType $member): string => $member instanceof ReflectionNamedType ? $member->getName() : self::SYMFONY_REQUEST,
                $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : [],
            );

            if (array_filter($names, static fn (string $name): bool => is_a($name, self::SYMFONY_REQUEST, true)) === []) {
                continue;
            }

            if ($found !== null || $names !== [$class] || $parameter->isVariadic()) {
                return null;
            }

            $found = $parameter->getName();
        }

        return $found;
    }

    /**
     * A statement that validates the request's input as it stands: `$request->validate([...])` or
     * `Validator::make($request->all(), [...])`, alone, assigned, or with `->validate()`/`->validated()`
     * run on it — the calls {@see InlineRulesVisitor} harvests rules from, with a literal rules array.
     */
    private static function validates(Node\Stmt $statement, InputHandle $handle): bool
    {
        $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        if ($call instanceof Expr\Assign) {
            $call = $call->expr;
        }
        if ($call instanceof MethodCall
            && $call->var instanceof Expr\StaticCall
            && $call->name instanceof Node\Identifier
            && in_array($call->name->toString(), ['validate', 'validated'], true)
            && $call->getArgs() === []
            && ! $call->isFirstClassCallable()
        ) {
            $call = $call->var;
        }

        if (! $call instanceof Expr || ! InlineRulesVisitor::rulesArgumentOf($call) instanceof Expr\Array_) {
            return false;
        }

        if ($call instanceof MethodCall) {
            return $handle->is($call->var);
        }

        $data = $call instanceof Expr\StaticCall ? ($call->getArgs()[0]->value ?? null) : null;

        return $data instanceof MethodCall
            && $handle->is($data->var)
            && $data->name instanceof Node\Identifier
            && $data->name->toString() === 'all'
            && $data->getArgs() === [];
    }

    /**
     * Whether a class's own `withValidator()` leaves the built validator's data alone ({@see givesOtherData()}).
     * `$variable` is the name it receives the validator under, where it is one.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function keepsValidatorData(ReflectionClass $class, ?string $variable): bool
    {
        if (! self::isApplicationMethod($class, self::WITH_VALIDATOR)) {
            return true;
        }

        $method = ParsedClassFile::declarationOf($class->getMethod(self::WITH_VALIDATOR));

        return $method !== null && $method->stmts !== null && ! self::givesOtherData($method->stmts, $variable);
    }

    /**
     * Whether statements handed a built validator may point its rules at other input. Rules and `after()`
     * checks leave its data alone — the checks run once the rules have — but `setData()` anywhere replaces
     * it, and the validator handed to other code may meet one.
     *
     * @param  array<Node\Stmt>  $statements
     */
    private static function givesOtherData(array $statements, ?string $variable): bool
    {
        $finder = new NodeFinder;
        $setsData = $finder->findFirst($statements, static fn (Node $node): bool => ($node instanceof MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'setdata');
        if ($setsData !== null || $variable === null) {
            return $setsData !== null;
        }

        // Every use of the validator is as the receiver of a call on it, or a closure's own name for it;
        // anything else hands it on.
        $allowed = [];
        foreach ([...$finder->findInstanceOf($statements, MethodCall::class), ...$finder->findInstanceOf($statements, Node\Param::class), ...$finder->findInstanceOf($statements, Node\ClosureUse::class)] as $node) {
            $allowed[spl_object_id($node->var)] = true;
        }

        return $finder->findFirst($statements, static fn (Node $node): bool => $node instanceof Expr\Variable
            && ($node->name === $variable || ! is_string($node->name))
            && ! isset($allowed[spl_object_id($node)])) !== null;
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
    private static function sourceOf(Expr $value, InputHandle $handle): ?array
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

        // `$request->headers->get('…')`: the bag `header()` itself reads.
        if ($method === 'get'
            && $value->var instanceof Expr\PropertyFetch
            && $handle->is($value->var->var)
            && $value->var->name instanceof Node\Identifier
            && $value->var->name->toString() === 'headers'
        ) {
            return HeaderNames::isToken($name) ? ['in' => self::HEADER, 'name' => $name] : null;
        }

        if (! $handle->is($value->var)) {
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
