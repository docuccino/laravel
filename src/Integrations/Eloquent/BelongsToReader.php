<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads a model's `belongsTo` relations statically: reflection finds the candidate methods, the
 * declaring file's AST supplies the call's LITERAL arguments. The model is never instantiated. A call
 * that can't be read whole surfaces as a REFUSAL rather than vanishing — the consumer must know a
 * partially-readable relation exists, both to key caches on what was read and to refuse columns the
 * unreadable part could own. Only a related class that doesn't load stays invisible: there is no file
 * to record. A `getKeyName()` override in a method body — not the `$primaryKey` property — is
 * invisible to the default-key computation, as it is for path parameters.
 *
 * @phpstan-type BelongsToRelation array{related: class-string, foreignKey: string, ownerKey: ?string}
 * @phpstan-type BelongsToRefusal array{related: ?string, foreignKey: ?string}
 * @phpstan-type BelongsToRelations array{readable: list<BelongsToRelation>, refused: list<BelongsToRefusal>}
 */
final class BelongsToReader
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /** @var array<string, BelongsToRelations> */
    private array $memo = [];

    /** Laravel's `belongsTo()` parameters, in signature order — positional args map onto these names. */
    private const PARAMETERS = ['related', 'foreignKey', 'ownerKey', 'relation'];

    /**
     * Every `belongsTo` relation the model declares. `readable` entries carry a CONCRETE foreign key —
     * the explicit literal, else Laravel's own default (`snake($relation).'_'.<related key name>`).
     * `refused` entries are calls read only in part: the literal foreign key when that argument alone
     * was readable (null is a wildcard), and the related class when the argument names one that loads.
     *
     * @return BelongsToRelations
     */
    public function relations(string $model): array
    {
        return $this->memo[$model] ??= $this->read($model);
    }

    /**
     * @return BelongsToRelations
     */
    private function read(string $model): array
    {
        $relations = ['readable' => [], 'refused' => []];
        if (! class_exists($model)) {
            return $relations;
        }

        try {
            $methods = (new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC);
        } catch (Throwable) {
            return $relations;
        }

        // Candidates grouped by declaring file so each file parses once; a relation method is public,
        // non-static and callable with no arguments, and framework methods have nothing to declare.
        $byFile = [];
        foreach ($methods as $method) {
            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() !== 0
                || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')
            ) {
                continue;
            }

            $file = $method->getFileName();
            if ($file !== false) {
                $byFile[$file][] = $method->getName();
            }
        }

        foreach ($byFile as $file => $names) {
            $nodes = ParsedClassFile::methods($file);
            foreach ($names as $name) {
                $node = $nodes[$name] ?? null;
                if ($node === null) {
                    continue;
                }

                $result = $this->fromMethod($name, $node);
                if ($result['readable'] !== null) {
                    $relations['readable'][] = $result['readable'];
                }
                $relations['refused'] = [...$relations['refused'], ...$result['refused']];
            }
        }

        return $relations;
    }

    /**
     * What one method body declares. A single fully-literal `$this->belongsTo(...)` call (a chained
     * `->withDefault()` still contains exactly one) is readable; several calls in one body (a
     * conditional relation) are each a refusal — which one runs is a runtime fact — as is a call with
     * a non-literal argument or a target that isn't a loadable model.
     *
     * @return array{readable: BelongsToRelation|null, refused: list<BelongsToRefusal>}
     */
    private function fromMethod(string $method, ClassMethod $node): array
    {
        $calls = array_values(array_filter(
            (new NodeFinder)->findInstanceOf($node->stmts ?? [], MethodCall::class),
            static fn (MethodCall $call): bool => $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this'
                && $call->name instanceof Node\Identifier
                && $call->name->toString() === 'belongsTo'
                && ! $call->isFirstClassCallable(),
        ));
        if ($calls === []) {
            return ['readable' => null, 'refused' => []];
        }

        if (count($calls) !== 1) {
            return ['readable' => null, 'refused' => array_map(self::refusal(...), $calls)];
        }

        $arguments = self::arguments($calls[0]);
        $related = $arguments === null ? null : ($arguments['related'] ?? null);
        if ($arguments === null || $related === null || ! class_exists($related) || ! EloquentModelReflector::isModel($related)) {
            return ['readable' => null, 'refused' => [self::refusal($calls[0])]];
        }

        return [
            'readable' => [
                'related' => $related,
                'foreignKey' => $arguments['foreignKey']
                    ?? Str::snake($arguments['relation'] ?? $method).'_'.$this->reflector->facts($related)['keyName'],
                'ownerKey' => $arguments['ownerKey'] ?? null,
            ],
            'refused' => [],
        ];
    }

    /**
     * What a partially-readable call still discloses: its literal foreign key — a named column no
     * other answer may safely claim — and its related class when the argument names one that loads.
     * Positional mapping stops at an unpack; past it, positions are unknowable.
     *
     * @return BelongsToRefusal
     */
    private static function refusal(MethodCall $call): array
    {
        $related = null;
        $foreignKey = null;
        $positional = true;
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                $positional = false;

                continue;
            }

            $name = $arg->name?->toString() ?? ($positional ? (self::PARAMETERS[$index] ?? null) : null);
            if ($name === 'related') {
                $class = self::className($arg->value);
                $related = $class !== false && class_exists($class) ? $class : null;
            }
            if ($name === 'foreignKey') {
                $value = self::literal($arg->value);
                $foreignKey = $value === false ? null : $value;
            }
        }

        return ['related' => $related, 'foreignKey' => $foreignKey];
    }

    /**
     * The call's arguments mapped onto {@see self::PARAMETERS} (positional and named both), each value a
     * literal: a `X::class`/string class name for `related`, a string or an explicit `null` for the
     * rest. Anything else — an unpack, an unknown name, a computed value — refuses the whole call.
     *
     * @return array<string, string|null>|null
     */
    private static function arguments(MethodCall $call): ?array
    {
        $arguments = [];
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString() ?? (self::PARAMETERS[$index] ?? null);
            if ($name === null || ! in_array($name, self::PARAMETERS, true) || array_key_exists($name, $arguments)) {
                return null;
            }

            $value = $name === 'related' ? self::className($arg->value) : self::literal($arg->value);
            if ($value === false) {
                return null;
            }

            $arguments[$name] = $value;
        }

        return $arguments;
    }

    /** A `X::class` fetch (already NameResolver-qualified) or a string literal FQCN, else false. */
    private static function className(Node\Expr $expr): string|false
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class'
        ) {
            return $expr->class->toString();
        }

        return $expr instanceof Node\Scalar\String_ ? $expr->value : false;
    }

    /** A string literal, or an explicit `null` (the parameter's own default, spelled out), else false. */
    private static function literal(Node\Expr $expr): string|null|false
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null') {
            return null;
        }

        return false;
    }
}
