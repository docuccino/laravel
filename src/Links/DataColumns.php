<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Which of a model's columns a Data body sends verbatim, and under which member — the Data half of a
 * link's give, beside {@see BodyColumns}. A property carries a column where every construction the walk
 * saw ({@see DataSourceVisitor}) fills it from that column, read as laravel-data reads it:
 *
 * - `new XData(slug: $team->slug)` — the argument lands in the promoted property of that name;
 * - `XData::from($team)` — each property reads the model attribute its input name gives it, snake-cased
 *   where the model's `$snakeAttributes` says so (`NormalizedModel`);
 * - `XData::from(['slug' => $team->slug])` — a key fills the property whose input name it is, or the
 *   property of that name where its input name is not in the payload (`MapPropertiesDataPipe`).
 *
 * Only a plain copy counts: a readonly, non-nullable `int` or `string` property with nothing of
 * laravel-data's on it but a name map or a validation rule, in a class whose constructor does nothing, whose
 * own serialisation is laravel-data's, whose envelope is settled, and whose names are mapped in a way this
 * mirrors exactly. On the `from()` path, also no `from()`, `from*()`, pipeline or normalizer of the class's
 * own that could step in first, and no default or global cast that could stand in for the value. Anything
 * else gives nothing.
 *
 * @internal
 */
final class DataColumns
{
    private const string NS = 'Spatie\\LaravelData\\';

    private const string MAP_NAME = 'Spatie\\LaravelData\\Attributes\\MapName';

    private const string MAP_INPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapInputName';

    private const string MAP_OUTPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

    private const string NAME_MAPPER = 'Spatie\\LaravelData\\Mappers\\NameMapper';

    private const string VALIDATION_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\ValidationAttribute';

    /** The attributes of laravel-data's that leave a value as it was handed over. */
    private const array PLAIN_ATTRIBUTES = [
        self::MAP_NAME,
        self::MAP_INPUT_NAME,
        self::MAP_OUTPUT_NAME,
        'Spatie\\LaravelData\\Attributes\\MergeValidationRules',
    ];

    /** The methods a Data object is serialised and sent through. */
    private const array SERIALISATION = ['transform', 'toArray', 'jsonSerialize', 'toJson', 'all', 'toResponse', 'with', 'getAdditionalData', 'getWrap'];

    /**
     * The methods laravel-data builds an object through from what `from()` or `collect()` is handed, before
     * it reaches the constructor: the two themselves, and what they call.
     */
    private const array CREATION = ['from', 'collect', 'normalizers', 'pipeline', 'prepareForPipeline'];

    private const string MODEL_NORMALIZER = 'Spatie\\LaravelData\\Normalizers\\ModelNormalizer';

    private const string ARRAY_NORMALIZER = 'Spatie\\LaravelData\\Normalizers\\ArrayNormalizer';

    /** laravel-data's normalizers that pass a model and an array on untouched, to the next one. */
    private const array PASSING = [
        'Spatie\\LaravelData\\Normalizers\\ObjectNormalizer',
        'Spatie\\LaravelData\\Normalizers\\JsonNormalizer',
        'Spatie\\LaravelData\\Normalizers\\FormRequestNormalizer',
    ];

    /** laravel-data's normalizers that pass a model on untouched, beside {@see PASSING}. */
    private const array PASSING_MODELS = [self::ARRAY_NORMALIZER];

    /** laravel-data's normalizers that pass an array on untouched, beside {@see PASSING}. */
    private const array PASSING_ARRAYS = [
        self::MODEL_NORMALIZER,
        'Spatie\\LaravelData\\Normalizers\\ArrayableNormalizer',
    ];

    /** laravel-data's shipped `data.normalizers`. */
    private const array NORMALIZERS = [
        self::MODEL_NORMALIZER,
        'Spatie\\LaravelData\\Normalizers\\ArrayableNormalizer',
        'Spatie\\LaravelData\\Normalizers\\ObjectNormalizer',
        self::ARRAY_NORMALIZER,
        'Spatie\\LaravelData\\Normalizers\\JsonNormalizer',
    ];

    /** @var list<string> */
    private readonly array $normalizers;

    /** @var array<string, bool> class, property and path → whether the property is a plain copy */
    private array $plain = [];

    /** @var array<string, array<string, true>> class → the constructor parameters that have a default */
    private array $defaults = [];

    /**
     * @param  list<string>|null  $normalizers  the app's `data.normalizers`, null where it sets none
     * @param  list<string>  $castTypes  the types the app's `data.casts` names
     * @param  list<string>  $transformerTypes  the types the app's `data.transformers` names
     */
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly WrapResolver $wrap = new WrapResolver,
        private readonly BodyColumns $columns = new BodyColumns,
        private readonly ?string $globalInputMapper = null,
        private readonly ?string $globalOutputMapper = null,
        ?array $normalizers = null,
        private readonly array $castTypes = [],
        private readonly array $transformerTypes = [],
    ) {
        $this->normalizers = $normalizers ?? self::NORMALIZERS;
    }

    /**
     * Member → [model, column] for every member of `$published` the Data body sends as a model's column,
     * verbatim.
     *
     * @param  list<DataConstruction>  $constructions  every construction of `$data` the walk saw
     * @param  list<string>  $published  the members the body's schema documents
     * @return array<string, array{0: string, 1: string}>
     */
    public function carried(string $data, array $constructions, array $published, RouteContext $context): array
    {
        if ($constructions === [] || ! class_exists($data)) {
            return [];
        }

        $context->recordDependencyFiles(DeclarationFiles::of($data));

        if (! $this->sendsAsDeclared($data)) {
            return [];
        }

        $agreed = null;
        foreach ($constructions as $construction) {
            $filled = $this->filled($data, $construction);
            $agreed = $agreed === null ? $filled : array_filter($agreed, static fn (array $source, string $property): bool => ($filled[$property] ?? null) === $source, ARRAY_FILTER_USE_BOTH);
        }

        $carried = [];
        foreach ($agreed as $property => [$model, $column]) {
            $member = $this->name($data, $property, self::MAP_OUTPUT_NAME, $this->globalOutputMapper);

            // The member is the one the schema publishes, or the pointer reads a member laravel-data does not send.
            if ($member === null || $member !== $this->reflector->outputName($data, $property) || ! in_array($member, $published, true)) {
                continue;
            }

            $column = $this->columns->sentAsStored($model, $column, $context);
            if ($column !== null && ! self::shadowed($model, $column)) {
                $carried[$member] = [$model, $column];
            }
        }

        return $carried;
    }

    /**
     * Property → source for one construction: only the properties it fills from a model's column, as a
     * plain copy.
     *
     * @param  class-string  $data
     * @return array<string, array{0: string, 1: string}>
     */
    private function filled(string $data, DataConstruction $construction): array
    {
        $filled = match ($construction->kind) {
            DataConstruction::ARGUMENTS => $this->fromArguments($data, $construction->sources),
            DataConstruction::MODEL => $construction->model !== null && $this->createsAsDeclared($data, self::MODEL_NORMALIZER)
                ? $this->fromModel($data, $construction->model)
                : [],
            DataConstruction::PAYLOAD => $this->createsAsDeclared($data, self::ARRAY_NORMALIZER) ? $this->fromPayload($data, $construction->sources) : [],
            default => [],
        };

        $fromPipeline = $construction->kind !== DataConstruction::ARGUMENTS;

        return array_filter($filled, fn (string $property): bool => $this->plain($data, $property, $fromPipeline), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<int|string, array{0: string, 1: string}|null>  $sources
     * @param  class-string  $data
     * @return array<string, array{0: string, 1: string}>
     */
    private function fromArguments(string $data, array $sources): array
    {
        $constructor = (new ReflectionClass($data))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $parameters = $constructor->getParameters();
        $byName = [];
        foreach ($parameters as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        $filled = [];
        foreach ($sources as $argument => $source) {
            $parameter = is_int($argument) ? ($parameters[$argument] ?? null) : ($byName[$argument] ?? null);

            // A promoted parameter is the property; any other hands its value to code that may change it.
            if ($source !== null && $parameter !== null && $parameter->isPromoted() && ! $parameter->isVariadic()) {
                $filled[$parameter->getName()] = $source;
            }
        }

        return $filled;
    }

    /**
     * @param  class-string  $data
     * @return array<string, array{0: string, 1: string}>
     */
    private function fromModel(string $data, string $model): array
    {
        if (! class_exists($model)) {
            return [];
        }

        // `NormalizedModel` reads the attribute under the input name, snake-cased while the model says so.
        $snake = (new ReflectionProperty($model, 'snakeAttributes'))->getValue() !== false;

        $filled = [];
        foreach (self::properties($data) as $property) {
            $input = $this->name($data, $property, self::MAP_INPUT_NAME, $this->globalInputMapper);
            if ($input !== null && ! str_contains($input, '.')) {
                $filled[$property] = [$model, $snake ? Str::snake($input) : $input];
            }
        }

        return $filled;
    }

    /**
     * @param  array<int|string, array{0: string, 1: string}|null>  $sources
     * @param  class-string  $data
     * @return array<string, array{0: string, 1: string}>
     */
    private function fromPayload(string $data, array $sources): array
    {
        $filled = [];
        foreach (self::properties($data) as $property) {
            $input = $this->name($data, $property, self::MAP_INPUT_NAME, $this->globalInputMapper);
            if ($input === null || str_contains($input, '.') || str_contains($property, '.')) {
                continue;
            }

            // The mapped name wins where the payload has it; the property's own name fills it otherwise.
            $key = array_key_exists($input, $sources) ? $input : $property;
            $source = $sources[$key] ?? null;
            if ($source !== null) {
                $filled[$property] = $source;
            }
        }

        return $filled;
    }

    /**
     * Whether what the class sends is its properties under their mapped names and nothing else: no
     * serialisation of its own, a constructor that does nothing, an envelope settled, and nothing of
     * laravel-data's on the class but a name map or a validation switch.
     *
     * @param  class-string  $data
     */
    private function sendsAsDeclared(string $data): bool
    {
        if ($this->overridden($data, self::SERIALISATION) || $this->wrap->diagnose($data) !== null) {
            return false;
        }

        $constructor = (new ReflectionClass($data))->getConstructor();
        if ($constructor !== null && ! self::isSpatie($constructor)) {
            $node = ParsedClassFile::declarationOf($constructor);
            if ($node === null || ($node->stmts ?? []) !== []) {
                return false;
            }
        }

        for ($class = new ReflectionClass($data); $class !== false; $class = $class->getParentClass()) {
            if (! self::plainAttributes($class->getAttributes())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `from()` reaches the constructor with what the normalizer read, untouched: no `from()` of
     * the class's own, no custom creation method it would call instead, no pipeline or normalizer of the
     * class's own, and the first normalizer that takes the payload is `$normalizer`.
     *
     * @param  class-string  $data
     */
    private function createsAsDeclared(string $data, string $normalizer): bool
    {
        $class = new ReflectionClass($data);
        if ($class->isAbstract() || $this->overridden($data, self::CREATION)) {
            return false;
        }

        // laravel-data calls any public static `from*()` that accepts the payload in place of building one.
        foreach ($class->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'from') && ! in_array($method->getName(), ['from', 'collect', 'collection'], true)) {
                return false;
            }
        }

        $passing = [...self::PASSING, ...($normalizer === self::MODEL_NORMALIZER ? self::PASSING_MODELS : self::PASSING_ARRAYS)];
        foreach ($this->normalizers as $configured) {
            $configured = ltrim($configured, '\\');
            if ($configured === $normalizer) {
                return true;
            }
            if (! in_array($configured, $passing, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether a property is copied through as it is handed over: public, readonly, declared `int` or
     * `string` and nothing else, no hook, nothing of laravel-data's on it but a name map or a validation rule, and no
     * global transformer for its type. Through the pipeline, also no default and no global cast.
     *
     * A fact of the class, so read once per property however many constructions and operations ask.
     *
     * @param  class-string  $data
     */
    private function plain(string $data, string $property, bool $fromPipeline): bool
    {
        return $this->plain[$data."\0".$property."\0".($fromPipeline ? 'pipeline' : 'constructor')] ??= $this->readPlain($data, $property, $fromPipeline);
    }

    /**
     * @param  class-string  $data
     */
    private function readPlain(string $data, string $property, bool $fromPipeline): bool
    {
        $reflection = new ReflectionProperty($data, $property);
        $type = $reflection->getType();
        // A writable property holds the copy only until something sets it again, which the walk does not follow.
        if (! $reflection->isPublic() || $reflection->isStatic() || ! $reflection->isReadOnly() || ! $type instanceof ReflectionNamedType || $type->allowsNull() || ! in_array($type->getName(), ['int', 'string'], true)) {
            return false;
        }

        // A hook decides what a read returns (PHP 8.4).
        // @phpstan-ignore function.impossibleType, method.notFound
        if ((method_exists($reflection, 'hasHooks') && $reflection->hasHooks()) || ! self::plainAttributes($reflection->getAttributes()) || in_array($type->getName(), $this->transformerTypes, true)) {
            return false;
        }

        if (! $fromPipeline) {
            return true;
        }

        $default = $reflection->isPromoted() ? isset(($this->defaults[$data] ??= self::defaulted($data))[$property]) : $reflection->hasDefaultValue();

        return ! $default && ! in_array($type->getName(), $this->castTypes, true);
    }

    /**
     * The name laravel-data gives a property in one direction, as `NameMappersResolver` and
     * `DataPropertyFactory` resolve it: the property's own map, else the global strategy, else the class's
     * map (a literal there is ignored). Null where it names a mapper this does not know, or hands one over
     * as an object.
     *
     * @param  class-string  $data
     */
    private function name(string $data, string $property, string $directional, ?string $global): ?string
    {
        $reflection = new ReflectionProperty($data, $property);
        $map = self::first($reflection->getAttributes(), $directional);
        if ($map !== null) {
            return self::mapped(self::mapValue($map, $directional), $property, literal: true);
        }

        if ($global !== null) {
            return self::mapped($global, $property, literal: true);
        }

        $attributes = [];
        for ($class = new ReflectionClass($data); $class !== false; $class = $class->getParentClass()) {
            array_push($attributes, ...$class->getAttributes());
        }
        $map = self::first($attributes, $directional);

        return $map === null ? $property : self::mapped(self::mapValue($map, $directional), $property, literal: false);
    }

    /**
     * The directional map, else the symmetric one, among `$attributes` in order.
     *
     * @param  list<ReflectionAttribute<object>>  $attributes
     * @return ReflectionAttribute<object>|null
     */
    private static function first(array $attributes, string $directional): ?ReflectionAttribute
    {
        foreach ([$directional, self::MAP_NAME] as $wanted) {
            foreach ($attributes as $attribute) {
                if ($attribute->getName() === $wanted) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function mapValue(ReflectionAttribute $attribute, string $directional): mixed
    {
        $instance = $attribute->newInstance();

        return $directional === self::MAP_OUTPUT_NAME ? ($instance->output ?? null) : ($instance->input ?? null);
    }

    /**
     * A map value applied to a property: a known mapper's transform, a literal (where it counts as one),
     * or null where it is neither.
     */
    private static function mapped(mixed $value, string $property, bool $literal): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (interface_exists(self::NAME_MAPPER) && is_a($value, self::NAME_MAPPER, true)) {
            return DataClassReflector::mapWithMapper($value, $property);
        }

        return $literal ? $value : $property;
    }

    /**
     * Whether each attribute either is not laravel-data's or leaves a value as it was handed over.
     *
     * @param  list<ReflectionAttribute<object>>  $attributes
     */
    private static function plainAttributes(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            $name = $attribute->getName();
            if (in_array($name, self::PLAIN_ATTRIBUTES, true) || ! class_exists($name) || is_a($name, self::VALIDATION_ATTRIBUTE, true)) {
                continue;
            }

            foreach ([$name, ...class_parents($name), ...class_implements($name)] as $kind) {
                if (str_starts_with($kind, self::NS)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a model declares a property or a method that a read of `$column` would reach first: a
     * declared property is read in place of the attribute, and a loaded relation of that name is what
     * laravel-data hands over.
     */
    private static function shadowed(string $model, string $column): bool
    {
        if (property_exists($model, $column)) {
            return true;
        }

        foreach (array_unique([$column, Str::camel($column)]) as $method) {
            if (method_exists($model, $method) && ! str_starts_with((new ReflectionMethod($model, $method))->getDeclaringClass()->getName(), 'Illuminate\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $methods
     * @param  class-string  $data
     */
    private function overridden(string $data, array $methods): bool
    {
        foreach ($methods as $name) {
            if (method_exists($data, $name) && ! self::isSpatie(new ReflectionMethod($data, $name))) {
                return true;
            }
        }

        return false;
    }

    /** Whether laravel-data itself declares the method — by the file it is written in, so a trait counts. */
    private static function isSpatie(ReflectionMethod $method): bool
    {
        $base = (new ReflectionClass(DataClassReflector::DATA))->getFileName();

        return $base !== false && str_starts_with((string) $method->getFileName(), dirname($base).DIRECTORY_SEPARATOR);
    }

    /**
     * The constructor parameters whose default `from()` could fall back to.
     *
     * @param  class-string  $data
     * @return array<string, true>
     */
    private static function defaulted(string $data): array
    {
        $defaulted = [];
        foreach ((new ReflectionClass($data))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaulted[$parameter->getName()] = true;
            }
        }

        return $defaulted;
    }

    /**
     * The properties laravel-data reads and writes: public and not static.
     *
     * @param  class-string  $data
     * @return list<string>
     */
    private static function properties(string $data): array
    {
        $properties = [];
        foreach ((new ReflectionClass($data))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $properties[] = $property->getName();
            }
        }

        return $properties;
    }
}
