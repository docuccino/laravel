<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Eloquent\AccessorReader;
use Docuccino\Laravel\Integrations\Eloquent\DateColumnSchema;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which of a model's columns a response body sends verbatim, and under which member — the half of a link
 * the body gives. A model sends each column under its own name; a resource sends what its `toArray()`
 * returns, and a member is the column only where it is written `$this->column` (or `$this->resource->column`,
 * `$this->getRouteKey()`, `$this->getKey()`).
 *
 * A column is sent verbatim only where nothing between the database and the wire rewrites it: no accessor,
 * no date handling, no cast but `int`/`integer`/`string`, and no attribute read the model writes itself. A route binding looks the segment up by the
 * stored value, so a member an accessor or a cast class produced is not a value the route matches on.
 *
 * @internal
 */
final class BodyColumns
{
    private const string MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    /** Casts that leave a key's value as the route matches it. */
    private const array VERBATIM_CASTS = ['int', 'integer', 'string'];

    /** Stands for the column `getRouteKey()` reads. */
    public const string ROUTE_KEY = "\0routeKey";

    /** Stands for the column `getKey()` reads. */
    public const string PRIMARY_KEY = "\0primaryKey";

    public function __construct(
        private readonly EloquentModelReflector $models = new EloquentModelReflector,
        private readonly AccessorReader $accessors = new AccessorReader,
    ) {}

    /**
     * Member → column for every member of `$published` the body of `$class` sends as one of `$model`'s
     * columns, verbatim.
     *
     * @param  list<string>  $published  the members the body's schema documents
     * @return array<string, string>
     */
    public function carried(string $class, string $model, array $published, RouteContext $context): array
    {
        $context->recordDependencyFiles(DeclarationFiles::of($model));

        $members = [];
        if ($class === $model) {
            // A model that writes its own serialised form may send anything under a column's name.
            $members = EloquentModelReflector::serialisesItself($model) ? [] : array_combine($published, $published);
        } else {
            $context->recordDependencyFiles(DeclarationFiles::of($class));
            $members = $this->resourceMembers($class);
        }

        $carried = [];
        foreach ($members as $member => $column) {
            $column = in_array($member, $published, true) ? $this->sentAsStored($model, $column, $context) : null;

            if ($column !== null) {
                $carried[(string) $member] = $column;
            }
        }

        return $carried;
    }

    /**
     * The column `$column` (or a key marker) names on `$model`, where its value reaches the wire as it is
     * stored; null otherwise.
     */
    public function sentAsStored(string $model, string $column, RouteContext $context): ?string
    {
        $context->recordDependencyFiles(DeclarationFiles::of($model));

        $column = match ($column) {
            self::ROUTE_KEY => $context->routeBindingKeyName($model),
            self::PRIMARY_KEY => $this->primaryKey($model),
            default => $column,
        };

        return $column !== null && $this->verbatim($model, $column) ? $column : null;
    }

    /**
     * The model a resource names with `@mixin`, where it names exactly one model.
     */
    public static function mixin(string $resource): ?string
    {
        if (! class_exists($resource)) {
            return null;
        }

        $found = [];

        for ($class = new ReflectionClass($resource); $class !== false && $class->getName() !== ResourceReflector::JSON_RESOURCE; $class = $class->getParentClass()) {
            $doc = $class->getDocComment();
            if ($doc === false || preg_match_all('/@mixin\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $doc, $matches) < 1) {
                continue;
            }

            $imports = ImportContext::forFile($class->getFileName() === false ? null : $class->getFileName());
            foreach ($matches[1] as $name) {
                $found[$imports->resolve($name)] = true;
            }

            break;
        }

        $models = array_keys($found);

        return count($models) === 1 && EloquentModelReflector::isModel($models[0]) ? $models[0] : null;
    }

    /**
     * Member → column (or a key marker) for what the resource's `toArray()` returns. A member every
     * return does not write the same way is left out, and a resource keeping Laravel's own `toArray()`
     * writes nothing a schema documents, so it gives nothing.
     *
     * @return array<string, string>
     */
    private function resourceMembers(string $resource): array
    {
        $method = new ReflectionMethod($resource, 'toArray');
        $node = $method->getDeclaringClass()->getName() === ResourceReflector::JSON_RESOURCE ? null : ParsedClassFile::declarationOf($method);

        $members = null;
        foreach ($node === null ? [] : self::returns($node) as $returned) {
            $written = $returned instanceof Node\Expr\Array_ ? self::written($returned) : [];
            $members = $members === null ? $written : array_intersect_assoc($members, $written);
        }

        return $members ?? [];
    }

    /**
     * The members an array literal writes as a column of `$this`.
     *
     * @return array<string, string>
     */
    private static function written(Node\Expr\Array_ $array): array
    {
        $written = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_ || $item->unpack) {
                continue;
            }

            $column = self::column($item->value);
            if ($column !== null) {
                $written[$item->key->value] = $column;
            }
        }

        return $written;
    }

    /** The column `$value` reads off the resource's model, a key marker, or null for anything else. */
    private static function column(Node\Expr $value): ?string
    {
        if ($value instanceof Node\Expr\PropertyFetch && $value->name instanceof Node\Identifier) {
            $on = $value->var;
            $onThis = self::isThis($on)
                || ($on instanceof Node\Expr\PropertyFetch && self::isThis($on->var) && $on->name instanceof Node\Identifier && $on->name->toString() === 'resource');

            return $onThis ? $value->name->toString() : null;
        }

        if ($value instanceof Node\Expr\MethodCall && self::isThis($value->var) && $value->name instanceof Node\Identifier && $value->getArgs() === []) {
            return match ($value->name->toString()) {
                'getRouteKey' => self::ROUTE_KEY,
                'getKey' => self::PRIMARY_KEY,
                default => null,
            };
        }

        return null;
    }

    private static function isThis(Node\Expr $node): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === 'this';
    }

    /**
     * What every `return` in the method body returns, closures' own returns aside.
     *
     * @return list<Node\Expr>
     */
    private static function returns(ClassMethod $method): array
    {
        $returns = [];
        $walk = static function (array $nodes) use (&$walk, &$returns): void {
            foreach ($nodes as $node) {
                if ($node instanceof Node\FunctionLike) {
                    continue;
                }
                if ($node instanceof Node\Stmt\Return_) {
                    $returns[] = $node->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));
                }
                if ($node instanceof Node) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $child = $node->{$name};
                        $walk(is_array($child) ? $child : [$child]);
                    }
                }
            }
        };
        $walk($method->stmts ?? []);

        return $returns;
    }

    /** The model's primary key, where nothing overrides how it is named. */
    private function primaryKey(string $model): ?string
    {
        if ((new ReflectionMethod($model, 'getKeyName'))->getDeclaringClass()->getName() !== self::MODEL) {
            return null;
        }

        return $this->models->facts($model)['keyName'];
    }

    /** Whether `$column` reaches the wire as it is stored. */
    private function verbatim(string $model, string $column): bool
    {
        // A model that reads its own attributes may hand back anything under a column's name.
        if (EloquentModelReflector::readsAttributesItself($model)) {
            return false;
        }

        $facts = $this->models->facts($model);
        $cast = $facts['casts'][$column] ?? null;

        // A date column is written as the framework serialises a date, never as it is stored.
        $dates = [
            ...$facts['dates'],
            ...($facts['timestamps'] ? DateColumnSchema::TIMESTAMPS : []),
            ...($facts['softDeletes'] ? [DateColumnSchema::DELETED_AT] : []),
        ];

        if (in_array($column, [...$facts['appends'], ...$dates], true) || ($cast !== null && ! in_array(strtolower($cast), self::VERBATIM_CASTS, true))) {
            return false;
        }

        foreach ($this->accessors->read($model) as $accessor) {
            if ($accessor['attribute'] === $column) {
                return false;
            }
        }

        return true;
    }
}
