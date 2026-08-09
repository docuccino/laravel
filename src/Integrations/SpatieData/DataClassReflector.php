<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\HiddenFromRequest;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Support\Fqcn;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Reflects a `spatie/laravel-data` Data class into the facts the schema/request mappers need, and is
 * the only place that touches the (optional) spatie surface. Everything spatie is referenced by FQCN
 * string, so there's no hard dependency — the integration only runs behind its `class_exists` guard.
 * Docuccino's own `#[Hidden]`/`#[SchemaName]`/`#[SchemaId]` are honoured alongside spatie's.
 *
 * Where a fact can't be recovered honestly this degrades to the plain property name / an omitted
 * fact plus a diagnostic; it never fabricates a key or a rule.
 */
final class DataClassReflector
{
    public const DATA = 'Spatie\\LaravelData\\Data';

    /** A method declared under the vendor namespace is spatie's own, not a user override. */
    private const SPATIE_NS = 'Spatie\\LaravelData\\';

    /**
     * Request partial query parameter → the `ResponsableData` static allow-list method that opts a
     * Data class into it. Spatie's base implementations return `[]` (nothing allowed), so only a user
     * OVERRIDE makes the matching `?include=`/`?exclude=`/`?only=`/`?except=` parameter live.
     *
     * @var array<string, string>
     */
    private const REQUEST_PARTIAL_METHODS = [
        'include' => 'allowedRequestIncludes',
        'exclude' => 'allowedRequestExcludes',
        'only' => 'allowedRequestOnly',
        'except' => 'allowedRequestExcept',
    ];

    /**
     * The global name-mapping strategy (`config('data.name_mapping_strategy.{input,output}')`) as
     * built-in mapper FQCNs, injected by the service provider so this class stays vendor-import-free.
     * A global mapper (commonly `SnakeCaseMapper`) renames every property key that carries no explicit
     * map attribute — spatie's `NameMappersResolver` falls back to config only in that branch.
     */
    public function __construct(
        private readonly ?string $globalInputMapper = null,
        private readonly ?string $globalOutputMapper = null,
    ) {}

    /**
     * Implemented by `Data`, the output-only `Resource` and the input-only `Dto` — none of which extend
     * one another, so triggers test this interface rather than `Data`.
     */
    public const BASE_DATA = 'Spatie\\LaravelData\\Contracts\\BaseData';

    public const DATA_COLLECTION = 'Spatie\\LaravelData\\DataCollection';

    /**
     * Shared by every collectable. The paginated variants do NOT extend `DataCollection` but do
     * implement this, so the collection trigger tests it.
     */
    public const BASE_COLLECTABLE = 'Spatie\\LaravelData\\Contracts\\BaseDataCollectable';

    public const OPTIONAL = 'Spatie\\LaravelData\\Optional';

    public const LAZY = 'Spatie\\LaravelData\\Lazy';

    private const SPATIE_HIDDEN = 'Spatie\\LaravelData\\Attributes\\Hidden';

    private const MAP_OUTPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

    private const MAP_INPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapInputName';

    private const MAP_NAME = 'Spatie\\LaravelData\\Attributes\\MapName';

    private const COMPUTED = 'Spatie\\LaravelData\\Attributes\\Computed';

    private const WITHOUT_VALIDATION = 'Spatie\\LaravelData\\Attributes\\WithoutValidation';

    private const FROM_ROUTE_PARAMETER = 'Spatie\\LaravelData\\Attributes\\FromRouteParameter';

    private const WITH_CAST = 'Spatie\\LaravelData\\Attributes\\WithCast';

    private const DATETIME_CAST = 'Spatie\\LaravelData\\Casts\\DateTimeInterfaceCast';

    private const DATA_COLLECTION_OF = 'Spatie\\LaravelData\\Attributes\\DataCollectionOf';

    private const RULE_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\Rule';

    private const ENUM_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\Enum';

    /**
     * spatie's built-in name mappers → the transform each applies. A mapper class passed to
     * `#[MapName(SnakeCaseMapper::class)]` renames every property; knowing the transform here is what
     * stops the mapper FQCN leaking as the documented JSON key.
     *
     * @var array<string, string>
     */
    private const MAPPERS = [
        'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper' => 'snake',
        'Spatie\\LaravelData\\Mappers\\CamelCaseMapper' => 'camel',
        'Spatie\\LaravelData\\Mappers\\StudlyCaseMapper' => 'studly',
        'Spatie\\LaravelData\\Mappers\\LowerCaseMapper' => 'lower',
        'Spatie\\LaravelData\\Mappers\\UpperCaseMapper' => 'upper',
    ];

    private const PAGINATED_COLLECTION = 'Spatie\\LaravelData\\PaginatedDataCollection';

    private const CURSOR_PAGINATED_COLLECTION = 'Spatie\\LaravelData\\CursorPaginatedDataCollection';

    private const VALIDATION_NS = 'Spatie\\LaravelData\\Attributes\\Validation\\';

    /**
     * Spatie validation attribute short name → Laravel rule name. Tokens go through the shared
     * validation chain, so `#[Max(100)]` documents identically to `'max:100'` on a FormRequest. Curated
     * to the common floor — an unmapped attribute degrades (see validationTokens) rather than guessing.
     *
     * @var array<string, string>
     */
    private const RULE_MAP = [
        'Required' => 'required', 'Nullable' => 'nullable', 'Sometimes' => 'sometimes',
        'Present' => 'present', 'Prohibited' => 'prohibited', 'Filled' => 'filled',
        'Email' => 'email', 'Url' => 'url', 'ActiveUrl' => 'active_url', 'Uuid' => 'uuid',
        'Ulid' => 'ulid', 'Numeric' => 'numeric', 'IntegerType' => 'integer', 'StringType' => 'string',
        'BooleanType' => 'boolean', 'ArrayType' => 'array', 'Alpha' => 'alpha', 'AlphaNumeric' => 'alpha_num',
        'AlphaDash' => 'alpha_dash', 'Date' => 'date', 'Json' => 'json', 'Ip' => 'ip',
        'Max' => 'max', 'Min' => 'min', 'Size' => 'size', 'Between' => 'between',
        'In' => 'in', 'NotIn' => 'not_in', 'Regex' => 'regex', 'DateFormat' => 'date_format',
        'MaxDigits' => 'max_digits', 'MinDigits' => 'min_digits', 'DigitsBetween' => 'digits_between',
        'StartsWith' => 'starts_with', 'EndsWith' => 'ends_with',
    ];

    /** Rules that carry comma-separated parameters (`max:100`, `in:a,b`); others are bare (`required`). */
    private const VALUE_RULES = [
        'max', 'min', 'size', 'between', 'in', 'not_in', 'regex', 'date_format',
        'max_digits', 'min_digits', 'digits_between', 'starts_with', 'ends_with',
    ];

    /** Any `BaseData` implementor that isn't itself a collectable — the schema mapper's trigger. */
    public static function isData(string $fqcn): bool
    {
        return $fqcn !== self::DATA
            && is_a($fqcn, self::BASE_DATA, true)
            && ! is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /** Any spatie collectable, plain or paginated — rendered as array/envelope. */
    public static function isDataCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /**
     * The item type of a collection generic. Spatie declares its collectables `<TKey of array-key,
     * TValue>`, so the item is the LAST type arg — reading arg 0 would document the key instead.
     * `PaginatedDataCollection<int, AuthorData>` and `DataCollection<AuthorData>` both give `AuthorData`;
     * a bare `DataCollection` gives null.
     */
    public static function collectionValueType(ClassT $type): ?DType
    {
        $args = $type->typeArgs;

        return $args === [] ? null : $args[array_key_last($args)];
    }

    /** spatie serialises `DateTimeInterface` values to a formatted string. */
    public static function isDateTime(string $fqcn): bool
    {
        return is_a($fqcn, \DateTimeInterface::class, true);
    }

    /**
     * Class-level schema identity plus the class-level `#[Hidden]` output deny-list, read through the
     * shared helper so a Data class honours them identically to a Resource or a model.
     *
     * @return array{hidden: list<string>, schemaName: ?string, schemaId: ?string}
     */
    public function classFacts(string $fqcn): array
    {
        return [
            'hidden' => SchemaIdentity::hidden($fqcn),
            'schemaName' => SchemaIdentity::name($fqcn),
            'schemaId' => SchemaIdentity::id($fqcn),
        ];
    }

    /**
     * The `format` of a `#[WithCast(DateTimeInterfaceCast::class, format: '…')]`, else null. Read from
     * the attribute arguments (nothing is instantiated). It governs the wire shape — notably `U`, which
     * serialises as an integer timestamp rather than a date-time string.
     */
    public function dateTimeCastFormat(string $fqcn, string $property): ?string
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return null;
        }

        $attributes = $reflection->getAttributes(self::WITH_CAST);
        if ($attributes === []) {
            return null;
        }

        $arguments = $attributes[0]->getArguments();
        if (($arguments[0] ?? null) !== self::DATETIME_CAST) {
            return null;
        }

        $format = $arguments['format'] ?? $arguments[1] ?? null;

        return is_string($format) ? $format : null;
    }

    /** Property-level `#[Hidden]`, either spatie's or Docuccino's. */
    public function isPropertyHidden(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        return $reflection->getAttributes(self::SPATIE_HIDDEN) !== []
            || $reflection->getAttributes(DocuccinoHidden::class) !== [];
    }

    /**
     * True when the declared type unions in spatie's `Optional` or `Lazy` marker
     * (`public string|Optional $foo`) — such a property is non-required on input and output.
     */
    public function isPropertyOptional(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        foreach ($this->typeNames($reflection) as $name) {
            if (is_a($name, self::OPTIONAL, true) || is_a($name, self::LAZY, true)) {
                return true;
            }
        }

        return false;
    }

    /** Envelope selector: `cursor`/`length` for the paginated variants, `simple` for a plain one. */
    public function collectionKind(string $fqcn): string
    {
        if (is_a($fqcn, self::CURSOR_PAGINATED_COLLECTION, true)) {
            return 'cursor';
        }

        return is_a($fqcn, self::PAGINATED_COLLECTION, true) ? 'length' : 'simple';
    }

    /**
     * Laravel rule tokens recovered from a property's spatie validation attributes, read via
     * {@see \ReflectionAttribute::getArguments()} — the attribute is never instantiated, so no spatie
     * rule logic runs. {@see DataValidationRules} feeds them through the shared validation chain.
     *
     * @return list<string>
     */
    public function validationTokens(string $fqcn, string $property): array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return [];
        }

        $tokens = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if (! str_starts_with($name, self::VALIDATION_NS)) {
                continue;
            }

            // `#[Rule('max:10|min:1')]` is spatie's escape hatch — its arguments already ARE Laravel
            // rule strings, so pass them straight through rather than emit a junk `rule:…` token.
            if ($name === self::RULE_ATTRIBUTE) {
                foreach ($this->ruleAttributeTokens($attribute->getArguments()) as $token) {
                    $tokens[] = $token;
                }

                continue;
            }

            // `#[Enum(Status::class)]` expands to `in:` over the backing values — the class name itself
            // is not an allowed value.
            if ($name === self::ENUM_ATTRIBUTE) {
                $token = $this->enumAttributeToken($attribute->getArguments());
                if ($token !== null) {
                    $tokens[] = $token;
                }

                continue;
            }

            $short = Fqcn::short($name);
            $mapped = self::RULE_MAP[$short] ?? null;
            // Unmapped attributes degrade to the snake-cased short name, which the shared chain then
            // treats like any unknown string rule (a transformer if one exists, else permissive + info).
            $rule = $mapped ?? self::snake($short);

            $parameters = $this->scalarArguments($attribute->getArguments());
            $carriesParameters = in_array($rule, self::VALUE_RULES, true) || ($mapped === null && $parameters !== '');

            $tokens[] = $carriesParameters && $parameters !== '' ? $rule.':'.$parameters : $rule;
        }

        return $tokens;
    }

    /**
     * The classes of any rule OBJECTS a property's `#[Rule(new Iban)]` attributes carry — spatie's one
     * attribute that takes one. Only the class is used; its `#[RuleSchema]` is the documented contract.
     *
     * @return list<string>
     */
    public function ruleObjectClasses(string $fqcn, string $property): array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return [];
        }

        $out = [];
        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() !== self::RULE_ATTRIBUTE) {
                continue;
            }

            // array_walk_recursive descends arrays and hands objects over as leaves, so a rule object
            // is found whether it was written bare or inside an array argument.
            $arguments = $attribute->getArguments();
            array_walk_recursive($arguments, static function (mixed $value) use (&$out): void {
                if (is_object($value)) {
                    $out[] = $value::class;
                }
            });
        }

        return $out;
    }

    /**
     * Every string in a `#[Rule(...)]` attribute's arguments, flattened — each one is a rule string.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    private function ruleAttributeTokens(array $arguments): array
    {
        $out = [];
        array_walk_recursive($arguments, static function (mixed $value) use (&$out): void {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        });

        return $out;
    }

    /**
     * `in:v1,v2` from a `#[Enum(Status::class)]` attribute's enum backing values, or null when the
     * class argument is missing / not a resolvable enum.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function enumAttributeToken(array $arguments): ?string
    {
        $class = null;
        array_walk_recursive($arguments, static function (mixed $value) use (&$class): void {
            if ($class === null && is_string($value) && $value !== '') {
                $class = ltrim($value, '\\');
            }
        });

        if ($class === null) {
            return null;
        }

        $values = array_map(strval(...), EnumReflection::values($class));

        return $values === [] ? null : 'in:'.implode(',', $values);
    }

    /**
     * Whether the property is out of the request shape: `#[Computed]` and `#[WithoutValidation]` are
     * never validated request fields, `#[FromRouteParameter]` comes from the route binding rather than
     * the body, and Docuccino's `#[HiddenFromRequest]` is the explicit opt-out.
     *
     * `#[Hidden]` does NOT exclude — it hides from output only, and "hidden from output but still
     * sendable" is exactly the shape the data-leakage lint exists to surface. See
     * docs/design/uir-and-extensions.md §7.
     */
    public function isExcludedFromRequest(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        return $reflection->getAttributes(self::COMPUTED) !== []
            || $reflection->getAttributes(self::WITHOUT_VALIDATION) !== []
            || $reflection->getAttributes(self::FROM_ROUTE_PARAMETER) !== []
            || $reflection->getAttributes(HiddenFromRequest::class) !== [];
    }

    /**
     * The property's constructor default, read off the signature — nothing is instantiated. A defaulted
     * property is non-required and its value is a documentable schema default.
     *
     * @return array{hasDefault: bool, value: mixed}
     */
    public function propertyDefault(string $fqcn, string $property): array
    {
        if (! class_exists($fqcn)) {
            return ['hasDefault' => false, 'value' => null];
        }

        $constructor = (new ReflectionClass($fqcn))->getConstructor();
        if ($constructor === null) {
            return ['hasDefault' => false, 'value' => null];
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $property && $parameter->isDefaultValueAvailable()) {
                $value = $parameter->getDefaultValue();

                return ['hasDefault' => is_scalar($value) || $value === null, 'value' => $value];
            }
        }

        return ['hasDefault' => false, 'value' => null];
    }

    /** The item Data class named by a property's `#[DataCollectionOf(X::class)]`, or null. */
    public function dataCollectionOf(string $fqcn, string $property): ?string
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return null;
        }

        $attributes = $reflection->getAttributes(self::DATA_COLLECTION_OF);
        if ($attributes === []) {
            return null;
        }

        foreach ($attributes[0]->getArguments() as $argument) {
            if (is_string($argument) && $argument !== '') {
                return ltrim($argument, '\\');
            }
        }

        return null;
    }

    /** A spatie `#[Prohibited]` property is documented as never-sendable. */
    public function isProhibited(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        return $reflection->getAttributes(self::VALIDATION_NS.'Prohibited') !== [];
    }

    /**
     * Mapper FQCNs used on the class or its properties that aren't recognised built-ins. The caller
     * turns these into a diagnostic, so an unknown mapper never silently mis-keys the schema.
     *
     * @return list<string>
     */
    public function unrecognisedMappers(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($fqcn);
        $found = [];

        $candidates = $reflection->getAttributes();
        foreach ($reflection->getProperties() as $property) {
            $candidates = [...$candidates, ...$property->getAttributes()];
        }

        foreach ($candidates as $attribute) {
            if (! in_array($attribute->getName(), [self::MAP_NAME, self::MAP_INPUT_NAME, self::MAP_OUTPUT_NAME], true)) {
                continue;
            }
            foreach ($attribute->getArguments() as $argument) {
                if (is_string($argument) && ! isset(self::MAPPERS[$argument]) && class_exists($argument) && ! in_array($argument, $found, true)) {
                    $found[] = $argument;
                }
            }
        }

        return $found;
    }

    /** CamelCase attribute short name → snake_case rule name (`StartsWith` → `starts_with`). */
    private static function snake(string $name): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $name);

        return strtolower($snake ?? $name);
    }

    /**
     * Attribute arguments as a comma-joined rule parameter string: `Between(1, 10)` → `"1,10"`,
     * `In(['a','b'])` → `"a,b"`. Non-scalars are dropped.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function scalarArguments(array $arguments): string
    {
        $flat = [];
        array_walk_recursive($arguments, static function (mixed $value) use (&$flat): void {
            if (is_scalar($value)) {
                $flat[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        });

        return implode(',', $flat);
    }

    /**
     * The property's declared named types, unions and intersections flattened.
     *
     * @return list<string>
     */
    private function typeNames(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        $names = [];
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType) {
                    $names[] = $member->getName();
                }
            }
        }

        return $names;
    }

    /** Output key, honouring `#[MapOutputName]` / `#[MapName]`, else the property name. */
    public function outputName(string $fqcn, string $property): string
    {
        return $this->mappedName($fqcn, $property, self::MAP_OUTPUT_NAME, $this->globalOutputMapper) ?? $property;
    }

    /** Input key, honouring `#[MapInputName]` / `#[MapName]`, else the property name. */
    public function inputName(string $fqcn, string $property): string
    {
        return $this->mappedName($fqcn, $property, self::MAP_INPUT_NAME, $this->globalInputMapper) ?? $property;
    }

    private function mappedName(string $fqcn, string $property, string $directional, ?string $globalMapper): ?string
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return null;
        }

        // Precedence: property-level beats class-level, directional beats symmetric MapName.
        $class = $reflection->getDeclaringClass();
        $sources = [
            $reflection->getAttributes($directional),
            $reflection->getAttributes(self::MAP_NAME),
            $class->getAttributes($directional),
            $class->getAttributes(self::MAP_NAME),
        ];

        $anyMapAttribute = false;
        foreach ($sources as $attributes) {
            if ($attributes !== []) {
                $anyMapAttribute = true;
            }
            $resolved = $this->resolveMapped($this->mapValue($attributes, $directional), $property);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // The global strategy applies only when no map attribute governs the property — that's where
        // spatie's NameMappersResolver consults config. An unrecognised one yields the property name.
        if (! $anyMapAttribute && $globalMapper !== null) {
            return self::mapWithMapper($globalMapper, $property);
        }

        return null;
    }

    /**
     * The partial query parameters a Data class opts into by overriding the matching
     * `allowedRequest*()` static. Detection is reflection-only — enumerating the allowed fields would
     * mean running the method, so the parameter is documented as a free comma-list string.
     *
     * @return list<string>
     */
    public function requestPartials(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($fqcn);
        $params = [];
        foreach (self::REQUEST_PARTIAL_METHODS as $param => $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }
            if (! str_starts_with($reflection->getMethod($method)->getDeclaringClass()->getName(), self::SPATIE_NS)) {
                $params[] = $param;
            }
        }

        return $params;
    }

    /**
     * The first map attribute's raw value — either a literal key or a mapper FQCN.
     *
     * @param  list<\ReflectionAttribute<object>>  $attributes
     */
    private function mapValue(array $attributes, string $directional): ?string
    {
        if ($attributes === []) {
            return null;
        }

        $instance = $attributes[0]->newInstance();
        $value = $directional === self::MAP_OUTPUT_NAME
            ? ($instance->output ?? null)
            : ($instance->input ?? null);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }

    /**
     * A map value resolved to the documented key: a known mapper applies its transform, a literal is
     * itself, and an unknown mapper class yields null so the caller falls back to the property name.
     */
    private function resolveMapped(?string $value, string $property): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $mapped = self::mapWithMapper($value, $property);
        if ($mapped !== null) {
            return $mapped;
        }

        // Never leak an unrecognised mapper's FQCN as the key — unrecognisedMappers() reports it.
        return class_exists($value) ? null : $value;
    }

    /**
     * A spatie built-in mapper applied by FQCN, or null when it isn't one. Public so the mapper table
     * is dataset-testable over every entry.
     */
    public static function mapWithMapper(string $mapperClass, string $property): ?string
    {
        return match (self::MAPPERS[$mapperClass] ?? null) {
            'snake' => Str::snake($property),
            'camel' => Str::camel($property),
            'studly' => Str::studly($property),
            'lower' => Str::lower($property),
            'upper' => Str::upper($property),
            default => null,
        };
    }

    private function property(string $fqcn, string $property): ?ReflectionProperty
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $reflection = new ReflectionClass($fqcn);

        return $reflection->hasProperty($property) ? $reflection->getProperty($property) : null;
    }
}
