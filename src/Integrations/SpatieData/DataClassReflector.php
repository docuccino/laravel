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
 * Reflects a `spatie/laravel-data` Data class into the presentation facts the schema/request
 * mappers need, keeping every touch of the (optional) spatie surface in one place. Spatie's own
 * attribute/marker classes are referenced by FQCN string — the integration carries no hard
 * dependency on the package (it is only ever exercised when the host app has it installed, the
 * `class_exists` registration guard). Docuccino's own `#[Hidden]`/`#[SchemaName]`/`#[SchemaId]`
 * are honoured alongside spatie's.
 */
final class DataClassReflector
{
    public const DATA = 'Spatie\\LaravelData\\Data';

    /** The vendor namespace prefix — a method declared under it is spatie's own, not a user override. */
    private const SPATIE_NS = 'Spatie\\LaravelData\\';

    /**
     * The `spatie/laravel-data` request query-string partial parameter → the static allow-list method
     * that opts a Data class into it (`ResponsableData`). A user OVERRIDE of one of these (declared
     * off the vendor namespace) makes the corresponding `?include=`/`?exclude=`/`?only=`/`?except=`
     * query parameter live on the response (docs: lazy-properties / partials). The base returns `[]`
     * (nothing allowed), so an un-overridden method is inert — only an override is documented.
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
     * The global default name-mapping strategy (`config('data.name_mapping_strategy.{input,output}')`)
     * as built-in mapper FQCNs, injected by the service provider (the integration stays
     * vendor-import-free, mirroring the Passport integration's runtime-config injection).
     * A whole-class default (commonly `SnakeCaseMapper::class`) renames EVERY property key that carries
     * no explicit `#[MapName]`/`#[MapInputName]`/`#[MapOutputName]` — spatie's `NameMappersResolver`
     * falls back to it precisely when no map attribute governs the property.
     */
    public function __construct(
        private readonly ?string $globalInputMapper = null,
        private readonly ?string $globalOutputMapper = null,
    ) {}

    /**
     * The interface every Data-like object implements — `Data`, the output-only `Resource`, and the
     * input-only `Dto` (none of which extend one another). The schema/request trigger tests it so all
     * three recommended base classes are recognised, not just `Data`.
     */
    public const BASE_DATA = 'Spatie\\LaravelData\\Contracts\\BaseData';

    public const DATA_COLLECTION = 'Spatie\\LaravelData\\DataCollection';

    /**
     * The interface every collectable shares — a plain `DataCollection` AND the paginated variants,
     * which do NOT extend `DataCollection` but do implement this. The collection trigger tests it.
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
     * spatie's built-in name mappers → the string transform each applies to a property name. A mapper
     * CLASS given to `#[MapName(SnakeCaseMapper::class)]` renames EVERY property by this transform;
     * mapping them here is what stops the mapper's FQCN leaking as the documented JSON key.
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
     * Spatie validation attribute short-name → Laravel rule name. The recovered token is fed through
     * the SHARED validation chain, so a spatie `#[Max(100)]` documents identically to `'max:100'` on
     * a FormRequest. Kept deliberately curated (the common DSL floor); an unmapped attribute is
     * ignored rather than guessed.
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

    /**
     * Whether an FQCN is a concrete spatie Data-like class (the schema mapper's trigger): any
     * implementor of `BaseData` — `Data`, `Resource`, `Dto` — that is not itself a collectable.
     */
    public static function isData(string $fqcn): bool
    {
        return $fqcn !== self::DATA
            && is_a($fqcn, self::BASE_DATA, true)
            && ! is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /** Whether an FQCN is any spatie collectable (plain or paginated) — rendered as array/envelope. */
    public static function isDataCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /**
     * The ITEM (value) type of a spatie collection generic. Spatie declares its collectables with
     * `@template TKey of array-key, @template TValue`, so the value type is the LAST type arg:
     * `PaginatedDataCollection<int, AuthorData>` → `AuthorData`, `DataCollection<AuthorData>` →
     * `AuthorData`, bare `DataCollection` → null. Reading typeArgs[0] documents the key, not the item.
     */
    public static function collectionValueType(ClassT $type): ?DType
    {
        $args = $type->typeArgs;

        return $args === [] ? null : $args[array_key_last($args)];
    }

    /** Whether an FQCN is a `DateTimeInterface` (spatie serialises these to a formatted string). */
    public static function isDateTime(string $fqcn): bool
    {
        return is_a($fqcn, \DateTimeInterface::class, true);
    }

    /**
     * The property names hidden from OUTPUT: class-level `#[Hidden(...)]` plus any property carrying
     * spatie's or Docuccino's property-level `#[Hidden]`.
     *
     * @return array{hidden: list<string>, schemaName: ?string, schemaId: ?string}
     */
    public function classFacts(string $fqcn): array
    {
        // Schema identity (name/id) and the class-level #[Hidden] deny-list are read through the shared
        // SchemaIdentity helper, so a Data class honours them identically to a Resource or a model.
        return [
            'hidden' => SchemaIdentity::hidden($fqcn),
            'schemaName' => SchemaIdentity::name($fqcn),
            'schemaId' => SchemaIdentity::id($fqcn),
        ];
    }

    /**
     * The `format` of a property's `#[WithCast(DateTimeInterfaceCast::class, format: '…')]`, or null
     * when the property has no such cast. The format is read from the attribute arguments by
     * reflection (nothing is instantiated); it governs the wire representation — notably `U` (a Unix
     * timestamp serialised as an integer) rather than the default date-time string.
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

    /** Whether the named property is hidden from output (property-level spatie/Docuccino `#[Hidden]`). */
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
     * Whether a property is optional in the (de)serialised shape: its declared type unions in
     * spatie's `Optional` or `Lazy` marker (`public string|Optional $foo`). Such a property is
     * absent from `required` on output and non-required on input.
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

    /**
     * The paginated-collection kind of a `DataCollection` FQCN, for envelope selection:
     * `length`/`cursor` for the paginated variants, `simple` for a plain `DataCollection`.
     */
    public function collectionKind(string $fqcn): string
    {
        if (is_a($fqcn, self::CURSOR_PAGINATED_COLLECTION, true)) {
            return 'cursor';
        }

        return is_a($fqcn, self::PAGINATED_COLLECTION, true) ? 'length' : 'simple';
    }

    /**
     * Laravel rule tokens recovered from a property's spatie validation attributes — read statically
     * via {@see \ReflectionAttribute::getArguments()} (never instantiated, so no user expression runs).
     * Fed through the shared validation chain by {@see DataValidationRules}.
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

            // `#[Rule('max:10|min:1')]` / `#[Rule(['max:10', 'min:1'])]` is spatie's escape hatch: its
            // arguments ARE Laravel rule strings, so hand them straight to the shared parser instead
            // of degrading to a garbage `rule:...` token.
            if ($name === self::RULE_ATTRIBUTE) {
                foreach ($this->ruleAttributeTokens($attribute->getArguments()) as $token) {
                    $tokens[] = $token;
                }

                continue;
            }

            // `#[Enum(Status::class)]` names a backed enum; expand it to its backing VALUES as an
            // `in:` rule (reusing the enum machinery) rather than the enum class name as the sole
            // allowed value.
            if ($name === self::ENUM_ATTRIBUTE) {
                $token = $this->enumAttributeToken($attribute->getArguments());
                if ($token !== null) {
                    $tokens[] = $token;
                }

                continue;
            }

            $short = Fqcn::short($name);
            $mapped = self::RULE_MAP[$short] ?? null;
            // A mapped attribute becomes its Laravel rule; an unmapped one degrades to the snake-cased
            // short name so the SHARED chain treats it exactly like an unknown string rule (a
            // transformer handles it if one exists — e.g. Exists — else a permissive + info diagnostic).
            $rule = $mapped ?? self::snake($short);

            $parameters = $this->scalarArguments($attribute->getArguments());
            $carriesParameters = in_array($rule, self::VALUE_RULES, true) || ($mapped === null && $parameters !== '');

            $tokens[] = $carriesParameters && $parameters !== '' ? $rule.':'.$parameters : $rule;
        }

        return $tokens;
    }

    /**
     * The rule tokens carried by a `#[Rule(...)]` attribute: each scalar argument (and each item of an
     * array argument) is a Laravel rule string.
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
     * Whether the property is excluded from the request shape:
     *  - a spatie `#[Computed]` (server-derived, output-only) or `#[WithoutValidation]` property is
     *    never a validated request field;
     *  - a spatie `#[FromRouteParameter]` property is populated from the route binding, not the request
     *    body, so it is not a sendable body field;
     *  - a Docuccino `#[HiddenFromRequest]` property is deliberately dropped from the request body.
     *
     * A property-level `#[Hidden]` is NOT excluded here: `#[Hidden]` hides from OUTPUT only, and a
     * property hidden from output but still present in the request is a real shape the data-leakage
     * lint is designed to surface — conflating the two would silently suppress that signal (see the
     * decision recorded in docs/design/uir-and-extensions.md §7). Request-hiding is the explicit
     * `#[HiddenFromRequest]` opt-in instead.
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
     * The property's constructor default: `['hasDefault' => bool, 'value' => mixed]`. A defaulted
     * property is optional (absent-from-required) and its value is a documentable schema default.
     * Read from the constructor signature by reflection — nothing is instantiated.
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

    /** Whether a property carries a spatie `#[Prohibited]` attribute (documented as never-sendable). */
    public function isProhibited(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        return $reflection->getAttributes(self::VALIDATION_NS.'Prohibited') !== [];
    }

    /**
     * The FQCNs of mapper classes used on the class or its properties that are NOT recognised built-in
     * spatie mappers — the caller emits a diagnostic so an unknown mapper never silently mis-keys the
     * schema (it falls back to the property name).
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
     * Flatten an attribute's raw arguments into a comma-joined scalar parameter string
     * (`Between(1, 10)` → `"1,10"`, `In(['a','b'])` → `"a,b"`); non-scalar args are dropped.
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
     * The named types a property declares (flattening a union/intersection), for marker detection.
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

    /** The OUTPUT key for a property, honouring `#[MapOutputName]` / `#[MapName]` (else the name). */
    public function outputName(string $fqcn, string $property): string
    {
        return $this->mappedName($fqcn, $property, self::MAP_OUTPUT_NAME, $this->globalOutputMapper) ?? $property;
    }

    /** The INPUT key for a property, honouring `#[MapInputName]` / `#[MapName]` (else the name). */
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

        // Precedence: a property-level map (directional beats symmetric MapName) wins over a
        // class-level map (which renames every property, commonly via a mapper class).
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

        // Global default strategy applies ONLY when no map attribute governs the property (spatie's
        // NameMappersResolver falls back to config only in the no-attribute branch); an unrecognised
        // global mapper class yields null → the property name (the honest floor, no FQCN leak).
        if (! $anyMapAttribute && $globalMapper !== null) {
            return self::mapWithMapper($globalMapper, $property);
        }

        return null;
    }

    /**
     * The request query-string partial parameters (`include`/`exclude`/`only`/`except`) a Data class
     * opts into by OVERRIDING the matching `allowedRequest*()` static method (declared off the vendor
     * namespace). Detection is reflection-only — the field allow-list itself is never enumerated (that
     * would need to run the method), so the parameter is documented as a free comma-list string.
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
     * The raw input/output value of the first map attribute (a literal name or a mapper-class FQCN),
     * or null when there is none.
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
     * Resolve a map value into the documented key: a known mapper class renames the property by its
     * transform; an UNKNOWN mapper class yields null (the caller falls back to the property name —
     * never the FQCN); anything else is a literal key.
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

        // An unrecognised mapper class must not leak its FQCN as the key; fall back to the property
        // name (the caller surfaces a diagnostic via unrecognisedMappers()).
        return class_exists($value) ? null : $value;
    }

    /**
     * Apply a spatie built-in name mapper (by FQCN) to a property name, or null when the FQCN is not
     * a recognised mapper. Public so the mapper table is dataset-testable over every entry.
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
