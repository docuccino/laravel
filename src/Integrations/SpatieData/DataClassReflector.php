<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\HiddenFromRequest;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Integrations\Support\EnumCaseValue;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;
use UnitEnum;

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

    /**
     * Implemented by everything that renders itself as a response — `Data`, `Resource` and every
     * collectable — which is exactly the set that inherits a `calculateResponseStatus()` default.
     */
    public const RESPONSABLE_DATA = 'Spatie\\LaravelData\\Contracts\\ResponsableData';

    public const OPTIONAL = 'Spatie\\LaravelData\\Optional';

    public const LAZY = 'Spatie\\LaravelData\\Lazy';

    private const SPATIE_HIDDEN = 'Spatie\\LaravelData\\Attributes\\Hidden';

    private const MAP_OUTPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

    private const MAP_INPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapInputName';

    private const MAP_NAME = 'Spatie\\LaravelData\\Attributes\\MapName';

    private const COMPUTED = 'Spatie\\LaravelData\\Attributes\\Computed';

    private const WITHOUT_VALIDATION = 'Spatie\\LaravelData\\Attributes\\WithoutValidation';

    private const FROM_ROUTE_PARAMETER = 'Spatie\\LaravelData\\Attributes\\FromRouteParameter';

    private const MERGE_VALIDATION_RULES = 'Spatie\\LaravelData\\Attributes\\MergeValidationRules';

    /** What `#[WithCast]`, `#[WithCastAndTransformer]` and `#[WithCastable]` implement, in every 4.x. */
    private const GETS_CAST = 'Spatie\\LaravelData\\Attributes\\GetsCast';

    private const WITH_TRANSFORMER = 'Spatie\\LaravelData\\Attributes\\WithTransformer';

    private const WITH_CAST_AND_TRANSFORMER = 'Spatie\\LaravelData\\Attributes\\WithCastAndTransformer';

    /** Present from laravel-data 4.14, whose attribute lookup reads `#[WithTransformer]` before `#[WithCastAndTransformer]`. */
    private const ATTRIBUTES_BY_KIND = 'Spatie\\LaravelData\\Support\\DataAttributesCollection';

    private const DATA_COLLECTION_OF = 'Spatie\\LaravelData\\Attributes\\DataCollectionOf';

    private const RULE_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\Rule';

    private const ENUM_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\Enum';

    /** The attributes laravel-data validates with whatever `getRule()` returns: a rule string or a rule object. */
    private const OBJECT_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\ObjectValidationAttribute';

    /**
     * Attributes that build a framework choice object from the values they are given, by its class, or take
     * one in place of their values: laravel-data validates with the object itself when it is the only argument.
     */
    private const CHOICE_ATTRIBUTES = [
        'Spatie\\LaravelData\\Attributes\\Validation\\In' => 'Illuminate\\Validation\\Rules\\In',
        'Spatie\\LaravelData\\Attributes\\Validation\\NotIn' => 'Illuminate\\Validation\\Rules\\NotIn',
    ];

    private const PASSWORD_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\Password';

    private const PASSWORD_RULE = 'Illuminate\\Validation\\Rules\\Password';

    /**
     * The attributes whose rule is a `DatabaseRule`, by the parameter naming its table. Laravel resolves a table
     * written as a model's class by constructing the model, which is application code.
     */
    private const DATABASE_ATTRIBUTES = [
        'Spatie\\LaravelData\\Attributes\\Validation\\Exists' => 'table',
        'Spatie\\LaravelData\\Attributes\\Validation\\Unique' => 'table',
    ];

    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const VALIDATION_PATH = 'Spatie\\LaravelData\\Support\\Validation\\ValidationPath';

    /**
     * spatie's built-in name mappers → the transform each applies. A mapper class passed to
     * `#[MapName(SnakeCaseMapper::class)]` renames every property; knowing the transform here is what
     * stops the mapper FQCN leaking as the documented JSON key.
     *
     * It lists every zero-argument mapper the package ships, and may list one the resolved version does
     * not have yet — spatie only treats a string as a mapper when the class exists, so a row for a
     * mapper that isn't installed is never reached (see resolveMapped). `ProvidedNameMapper` is not a
     * transform at all: it returns a fixed name, which is how spatie models a literal `#[MapName('key')]`.
     *
     * @var array<string, string>
     */
    private const MAPPERS = [
        'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper' => 'snake',
        'Spatie\\LaravelData\\Mappers\\CamelCaseMapper' => 'camel',
        'Spatie\\LaravelData\\Mappers\\StudlyCaseMapper' => 'studly',
        'Spatie\\LaravelData\\Mappers\\KebabCaseMapper' => 'kebab',
        'Spatie\\LaravelData\\Mappers\\LowerCaseMapper' => 'lower',
        'Spatie\\LaravelData\\Mappers\\UpperCaseMapper' => 'upper',
    ];

    private const PAGINATED_COLLECTION = 'Spatie\\LaravelData\\PaginatedDataCollection';

    private const CURSOR_PAGINATED_COLLECTION = 'Spatie\\LaravelData\\CursorPaginatedDataCollection';

    private const VALIDATION_NS = 'Spatie\\LaravelData\\Attributes\\Validation\\';

    /** The attributes laravel-data writes as a rule string, from their own `keyword()` and `parameters()`. */
    private const STRING_ATTRIBUTE = 'Spatie\\LaravelData\\Attributes\\Validation\\StringValidationAttribute';

    private const REQUIRING_RULE = 'Spatie\\LaravelData\\Support\\Validation\\RequiringRule';

    private const FIELD_REFERENCE = 'Spatie\\LaravelData\\Support\\Validation\\References\\FieldReference';

    /** Any `BaseData` implementor that isn't itself a collectable — the schema mapper's trigger. */
    public static function isData(string $fqcn): bool
    {
        return $fqcn !== self::DATA
            && is_a($fqcn, self::BASE_DATA, true)
            && ! is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /** Whether spatie renders this class itself, and so supplies it a response-status default. */
    public static function isResponsable(string $fqcn): bool
    {
        return interface_exists(self::RESPONSABLE_DATA) && is_a($fqcn, self::RESPONSABLE_DATA, true);
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
     * The cast laravel-data reads this property's input with, as the class and constructor arguments its
     * attribute names (nothing is instantiated), else null: the first attribute that gets a cast —
     * `#[WithCast]`, `#[WithCastAndTransformer]` or `#[WithCastable]` — in declared order.
     *
     * @return array{class: string, arguments: array<array-key, mixed>}|null
     */
    public function propertyCast(string $fqcn, string $property): ?array
    {
        $attributes = $this->property($fqcn, $property)?->getAttributes(self::GETS_CAST, ReflectionAttribute::IS_INSTANCEOF) ?? [];

        return $attributes === [] ? null : self::named($attributes[0]);
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

    /**
     * Envelope selector: `cursor`/`length` for the paginated variants, `plain` for an unpaginated
     * collection. Never `simple`: that token is a paginator KIND in the envelope builders, and a plain
     * collection is the absence of a paginator rather than one of them.
     */
    public function collectionKind(string $fqcn): string
    {
        if (is_a($fqcn, self::CURSOR_PAGINATED_COLLECTION, true)) {
            return 'cursor';
        }

        return is_a($fqcn, self::PAGINATED_COLLECTION, true) ? 'length' : 'plain';
    }

    /**
     * What a property's spatie validation attributes hand Laravel: the rule strings laravel-data writes for
     * them ({@see attributeRule()}), a `#[Rule(...)]`'s split as laravel-data splits them
     * ({@see RuleParsing::dataPieces()}), and the rule OBJECTS — any a `#[Rule(...)]` carries, bare or inside
     * an array, and the one any other attribute validates with ({@see handedObject()}).
     *
     * @param  string  $path  where the object declaring the property sits in the request (`range`,
     *                        `ranges.*`), empty at the root — what laravel-data resolves a field reference under
     * @return array{0: list<string>, 1: list<object>}
     */
    public function attributeRules(string $fqcn, string $property, string $path = ''): array
    {
        [$tokens, $objects] = [[], []];
        foreach ($this->property($fqcn, $property)?->getAttributes() ?? [] as $attribute) {
            if ($attribute->getName() === self::RULE_ATTRIBUTE) {
                $arguments = $attribute->getArguments();
                array_walk_recursive($arguments, static function (mixed $value) use (&$tokens, &$objects): void {
                    if (is_string($value)) {
                        $tokens = [...$tokens, ...RuleParsing::dataPieces($value)];
                    } elseif (is_object($value)) {
                        $objects[] = $value;
                    }
                });

                continue;
            }

            $handed = self::handedObject($attribute);
            $rule = $handed === null && str_starts_with($attribute->getName(), self::VALIDATION_NS) ? self::attributeRule($attribute, $path) : null;
            if ($handed !== null) {
                $objects[] = $handed;
            } elseif ($rule !== null) {
                $tokens[] = $rule;
            }
        }

        return [$tokens, $objects];
    }

    /**
     * The files of the enums a property's validation attributes quote values from — a case passed as a
     * parameter, or the class an `#[Enum]` lists — which key every rule built from them.
     *
     * @return list<string>
     */
    public function validationEnumFiles(string $fqcn, string $property): array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return [];
        }

        $files = [];
        foreach ($reflection->getAttributes() as $attribute) {
            if (! str_starts_with($attribute->getName(), self::VALIDATION_NS)) {
                continue;
            }

            $enum = $attribute->getName() === self::ENUM_ATTRIBUTE;
            $arguments = $attribute->getArguments();
            array_walk_recursive($arguments, static function (mixed $value) use (&$files, $enum): void {
                $class = match (true) {
                    $value instanceof UnitEnum => $value::class,
                    $enum && is_string($value) => ltrim($value, '\\'),
                    default => null,
                };
                $file = $class === null ? null : EnumReflection::file($class);
                if ($file !== null) {
                    $files[] = $file;
                }
            });
        }

        return array_values(array_unique($files));
    }

    /**
     * The rule object an attribute hands Laravel in place of a rule string, or null. An `#[In]`/`#[NotIn]` is the
     * object it is given, or the one it builds from its values ({@see builtChoice()}); any other object attribute
     * is the object its `getRule()` builds, as laravel-data builds it ({@see builtRule()}).
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function handedObject(ReflectionAttribute $attribute): ?object
    {
        if (isset(self::CHOICE_ATTRIBUTES[$attribute->getName()])) {
            return self::givenObject($attribute) ?? self::builtChoice($attribute);
        }

        $rule = self::builtRule($attribute);

        return is_object($rule) ? $rule : null;
    }

    /**
     * The `In`/`NotIn` an `#[In]`/`#[NotIn]` builds from its values, flattened as laravel-data flattens them.
     * Each value stays as written, so the list reads as `Rule::in()` does ({@see LiveRuleValue}): `true` as
     * `"1"` and `false` as `""`, the strings Laravel compares, and a reference only a request resolves as a
     * value this build cannot read.
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function builtChoice(ReflectionAttribute $attribute): object
    {
        $class = self::CHOICE_ATTRIBUTES[$attribute->getName()];

        $values = [];
        $arguments = $attribute->getArguments();
        array_walk_recursive($arguments, static function (mixed $value) use (&$values): void {
            $values[] = $value;
        });

        return new $class($values);
    }

    /**
     * The framework choice object an `#[In]` or `#[NotIn]` was given as its only argument, or null.
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function givenObject(ReflectionAttribute $attribute): ?object
    {
        $class = self::CHOICE_ATTRIBUTES[$attribute->getName()] ?? null;
        $arguments = array_values($attribute->getArguments());

        return $class !== null && count($arguments) === 1 && $arguments[0] instanceof $class ? $arguments[0] : null;
    }

    /**
     * What an object attribute validates with — the rule string or object its own `getRule()` builds, as
     * laravel-data builds it — or null for any other attribute, or where the rule cannot be built: an argument
     * this version does not take, or a reference only a request can resolve. A `#[Password(default: true)]` is
     * the default the application registers at boot, which no file this build keys on states, and a database
     * rule whose table is a model's class is resolved by constructing the model, so each is read as its bare
     * keyword instead.
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function builtRule(ReflectionAttribute $attribute): object|string|null
    {
        if (! is_a($attribute->getName(), self::OBJECT_ATTRIBUTE, true)) {
            return null;
        }

        if (self::tableIsModel($attribute)) {
            $class = $attribute->getName();

            return $class::keyword();
        }

        try {
            $instance = $attribute->newInstance();
            if (self::takesRegisteredDefault($instance)) {
                $class = $attribute->getName();

                return $class::keyword();
            }

            $rule = method_exists($instance, 'getRule') ? $instance->getRule(new (self::VALIDATION_PATH)) : null;
        } catch (Throwable) {
            return null;
        }

        return is_object($rule) || is_string($rule) ? $rule : null;
    }

    /**
     * Whether a database rule's table is written as a model's class, which `DatabaseRule` resolves as Laravel does.
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function tableIsModel(ReflectionAttribute $attribute): bool
    {
        $parameter = self::DATABASE_ATTRIBUTES[$attribute->getName()] ?? null;
        $arguments = $attribute->getArguments();
        $table = $parameter === null ? null : ($arguments[$parameter] ?? $arguments[0] ?? null);

        return is_string($table) && str_contains($table, '\\') && is_a($table, self::MODEL, true);
    }

    /** Whether a `#[Password]` validates with the default the application registered rather than its own arguments. */
    private static function takesRegisteredDefault(object $instance): bool
    {
        $rule = self::PASSWORD_RULE;
        $reflection = new ReflectionClass($instance);
        if (! is_a($instance, self::PASSWORD_ATTRIBUTE) || ! $reflection->hasProperty('default') || ! property_exists($rule, 'defaultCallback')) {
            return false;
        }

        // A rule object it is given wins over `default`, as it does in `getRule()`.
        $given = $reflection->hasProperty('rule') ? $reflection->getProperty('rule')->getValue($instance) : null;

        return $given === null && $reflection->getProperty('default')->getValue($instance) === true && $rule::$defaultCallback !== null;
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
     * property is non-required whatever the default is; `value` is separately the *documentable* default,
     * so a non-scalar one (`[]`, `new Optional`) still says "not required" while contributing no
     * `default` keyword.
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

                return ['hasDefault' => true, 'value' => is_scalar($value) ? $value : null];
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

    /**
     * What each `#[Prohibited]` on the property validates with, as laravel-data builds it: the `prohibited` rule
     * string, or the `ProhibitedIf` object it was handed.
     *
     * @return list<object|string>
     */
    public function prohibitions(string $fqcn, string $property): array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return [];
        }

        $out = [];
        foreach ($reflection->getAttributes(self::VALIDATION_NS.'Prohibited') as $attribute) {
            $rule = self::builtRule($attribute);
            if ($rule !== null) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * Whether the property carries one of spatie's `RequiringRule` attributes — `#[Required]` and the
     * `#[Required…]` family — which take the place of the `required` its type implies, whatever rule they go
     * on to build: a `#[Required(new RequiredIf(false))]` builds none, and the property is not required.
     */
    public function statesRequiringRule(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        foreach ($reflection->getAttributes() as $attribute) {
            if (is_a($attribute->getName(), self::REQUIRING_RULE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes a class-level spatie attribute may be written on, the concrete class first and then
     * every ancestor. Stated once here because three readings need it, and one that asks a single class
     * answers about the wrong file.
     *
     * PHP does not inherit class attributes; spatie does. `DataAttributesCollectionFactory` builds its
     * collection from the class being resolved and then walks `getParentClass()`, concatenating as it
     * goes, so `first()` answers the NEAREST declaration and a base class speaks for every subclass.
     * That has been the grammar since 3.9.1, which the `^4.0` this integration activates on is wholly
     * past — one chain to read, and no version to branch on.
     *
     * The subject is what spatie resolves, never whichever class happens to DECLARE the property being
     * asked about: an inherited property under a mapped subclass is renamed by the subclass's mapper.
     *
     * @return list<ReflectionClass<object>>
     */
    private static function classChain(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $chain = [];
        for ($class = new ReflectionClass($fqcn); $class !== false; $class = $class->getParentClass()) {
            $chain[] = $class;
        }

        return $chain;
    }

    /**
     * Whether the class or an ancestor carries `#[MergeValidationRules]`, which flips spatie's `rules()`
     * override from REPLACING the inferred rules at a key to appending to them. Inherited for the reason
     * {@see classChain()} gives.
     */
    public function mergesValidationRules(string $fqcn): bool
    {
        foreach (self::classChain($fqcn) as $class) {
            if ($class->getAttributes(self::MERGE_VALIDATION_RULES) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mapper FQCNs used on the class or its properties that aren't recognised built-ins. The caller
     * turns these into a diagnostic, so an unknown mapper never silently mis-keys the schema.
     *
     * The class half reads the whole chain ({@see classChain()}), because that is the set
     * {@see mappedName()} keys from: a mapper reported by neither is one that renames every key with
     * nothing said about it.
     *
     * @return list<string>
     */
    public function unrecognisedMappers(string $fqcn): array
    {
        $chain = self::classChain($fqcn);
        if ($chain === []) {
            return [];
        }

        $found = [];

        $candidates = [];
        foreach ($chain as $class) {
            $candidates = [...$candidates, ...$class->getAttributes()];
        }
        foreach ($chain[0]->getProperties() as $property) {
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

    /**
     * The rule string laravel-data writes for a validation attribute: the attribute's own `keyword()`, then
     * the `parameters()` it reports, as laravel-data's `RuleDenormalizer` joins them. An object attribute is the
     * rule string its own `getRule()` builds ({@see builtRule()}), and null where that is an object. Null too where
     * laravel-data cannot build the rule either, which fails every request.
     *
     * @param  ReflectionAttribute<object>  $attribute
     */
    private static function attributeRule(ReflectionAttribute $attribute, string $path): ?string
    {
        $class = $attribute->getName();
        if (! method_exists($class, 'keyword')) {
            return null;
        }

        // An object attribute that builds a rule string hands Laravel that string.
        $built = self::builtRule($attribute);
        if (is_string($built)) {
            return $built;
        }

        if (is_a($class, self::OBJECT_ATTRIBUTE, true)) {
            return null;
        }

        try {
            $keyword = $class::keyword();
            $instance = is_a($class, self::STRING_ATTRIBUTE, true) ? $attribute->newInstance() : null;
            $parameters = $instance !== null && method_exists($instance, 'parameters')
                ? $instance->parameters()
                : array_values($attribute->getArguments());
        } catch (Throwable) {
            return null;
        }

        if (! is_string($keyword) || ! is_array($parameters)) {
            return null;
        }

        $written = [];
        foreach ($parameters as $key => $parameter) {
            $value = self::ruleParameter($parameter, $path);
            if ($value !== null) {
                $written[] = is_string($key) ? $key.'='.$value : $value;
            }
        }

        return $written === [] ? $keyword : $keyword.':'.implode(',', $written);
    }

    /**
     * One rule parameter as laravel-data writes it, or null where it leaves it out. A bool is `true`/`false`, a
     * list its members joined, and an enum case the value Laravel casts it to ({@see EnumCaseValue}). A field
     * reference names its field under `$path` ({@see fieldName()}). A value only a request or the clock decides —
     * an external reference, a date — is left out too, as nothing the build can state.
     */
    private static function ruleParameter(mixed $value, string $path): ?string
    {
        if (is_array($value)) {
            return $value === [] ? null : implode(',', array_map(static fn (mixed $item): string => self::ruleParameter($item, $path) ?? '', $value));
        }

        return match (true) {
            is_string($value), is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof UnitEnum => (string) EnumCaseValue::ofLive($value),
            is_object($value) && is_a($value, self::FIELD_REFERENCE) => self::fieldName($value, $path),
            default => null,
        };
    }

    /**
     * The field a laravel-data field reference names: a sibling of the property, under the path of the object
     * declaring it, unless it was written `fromRoot`. laravel-data resolves it under that object's own path —
     * `range.min`, `ranges.0.min` — and `ranges.*.min` is that path in Laravel's own grammar, which reads the
     * `*` as the index of the item being validated.
     */
    private static function fieldName(object $reference, string $path): ?string
    {
        $fields = get_object_vars($reference);
        $name = $fields['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        return $path === '' || ($fields['fromRoot'] ?? null) === true ? $name : $path.'.'.$name;
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

        // Precedence: property-level beats class-level, directional beats symmetric MapName. Each class
        // level is the whole chain nearest-first ({@see classChain()}), which is the order spatie's own
        // `first()` answers in — so a directional map on a base still beats a symmetric one on the leaf.
        $sources = [
            $reflection->getAttributes($directional),
            $reflection->getAttributes(self::MAP_NAME),
            self::chainAttributes($fqcn, $directional),
            self::chainAttributes($fqcn, self::MAP_NAME),
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
     * One class-level attribute's occurrences down the chain, nearest declaration first.
     *
     * @return list<ReflectionAttribute<object>>
     */
    private static function chainAttributes(string $fqcn, string $attribute): array
    {
        $found = [];
        foreach (self::classChain($fqcn) as $class) {
            $found = [...$found, ...$class->getAttributes($attribute)];
        }

        return $found;
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
     * @param  list<ReflectionAttribute<object>>  $attributes
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

        // What counts as a mapper is a function of the version the app resolved: spatie only treats a
        // string as one when the class exists, and otherwise uses it verbatim as the key. So a name that
        // isn't installed — a mapper from a later release, say — is a literal here too, exactly as the
        // running application would key it.
        if (! class_exists($value)) {
            return $value;
        }

        // A recognised mapper transforms; an unrecognised one yields null so the caller falls back to the
        // property name, since its FQCN must never leak as the key. unrecognisedMappers() reports it.
        return self::mapWithMapper($value, $property);
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
            'kebab' => Str::kebab($property),
            'lower' => Str::lower($property),
            'upper' => Str::upper($property),
            default => null,
        };
    }

    /**
     * The Data item of a PLAIN nested collection, or null where the property is not one.
     *
     * Unpaginated is the whole point: a paginated collection carries `meta` and `links` beside its
     * items and {@see DataSchema} already publishes that envelope, so it is not a nested-wrap question
     * at all. {@see DataValidationRules::nestedData()} asks a related but different one — it descends for
     * request rules and has no reason to exclude a paginated shape.
     */
    public function nestedCollectionItem(string $fqcn, string $property, DType $clean): ?string
    {
        foreach ($clean instanceof UnionT ? $clean->members : [$clean] as $member) {
            // A collectable names its kind on the type, whatever the attribute says about its items.
            if ($member instanceof ClassT && self::isDataCollection($member->fqcn) && $this->collectionKind($member->fqcn) !== 'plain') {
                return null;
            }
        }

        $declared = $this->dataCollectionOf($fqcn, $property);
        if ($declared !== null && self::isData($declared)) {
            return $declared;
        }

        foreach ($clean instanceof UnionT ? $clean->members : [$clean] as $member) {
            $item = match (true) {
                $member instanceof ListT, $member instanceof MapT => $member->value,
                $member instanceof ClassT && self::isDataCollection($member->fqcn) => self::collectionValueType($member),
                default => null,
            };

            if ($item instanceof ClassT && self::isData($item->fqcn)) {
                return $item->fqcn;
            }
        }

        return null;
    }

    /**
     * Whether the property carries any `#[WithTransformer]`. Which transformer it is never matters —
     * one replaces serialisation outright, so the declared shape stops predicting the wire.
     *
     * A property the class does not declare carries no transformer, which is what this answers; it is
     * NOT the same question as whether the property exists, and a caller that needs that asks
     * {@see declaresProperty()}. Written as `?->…  !== []` the two collapsed, and the collapsed answer
     * was `true`: an unreadable property reported a transformer nobody wrote.
     */
    public function isPropertyTransformed(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);

        return $reflection !== null
            && $reflection->getAttributes(self::WITH_TRANSFORMER, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    /**
     * The transformer laravel-data writes this property with, as the class and constructor arguments its
     * attribute names (nothing is instantiated), else null. That is the first `#[WithTransformer]` or
     * `#[WithCastAndTransformer]`: from 4.14 a `#[WithTransformer]` before any `#[WithCastAndTransformer]`,
     * and before that whichever is declared first.
     *
     * @return array{class: string, arguments: array<array-key, mixed>}|null
     */
    public function propertyTransformer(string $fqcn, string $property): ?array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return null;
        }

        $attributes = array_values(array_filter(
            $reflection->getAttributes(),
            static fn (ReflectionAttribute $attribute): bool => is_a($attribute->getName(), self::WITH_TRANSFORMER, true)
                || is_a($attribute->getName(), self::WITH_CAST_AND_TRANSFORMER, true),
        ));

        if (class_exists(self::ATTRIBUTES_BY_KIND)) {
            // Stable, so each kind keeps its declared order.
            usort($attributes, static fn (ReflectionAttribute $a, ReflectionAttribute $b): int => (int) ! is_a($a->getName(), self::WITH_TRANSFORMER, true)
                <=> (int) ! is_a($b->getName(), self::WITH_TRANSFORMER, true));
        }

        if ($attributes === []) {
            return null;
        }

        return self::named($attributes[0]);
    }

    /**
     * The class a cast or transformer attribute names first, and the arguments it is constructed with.
     *
     * @param  ReflectionAttribute<object>  $attribute
     * @return array{class: string, arguments: array<array-key, mixed>}|null
     */
    private static function named(ReflectionAttribute $attribute): ?array
    {
        $arguments = $attribute->getArguments();
        $class = $arguments[0] ?? $arguments['castClass'] ?? $arguments['transformerClass'] ?? $arguments['class'] ?? $arguments['castableClass'] ?? null;
        unset($arguments[0], $arguments['castClass'], $arguments['transformerClass'], $arguments['class'], $arguments['castableClass']);

        return is_string($class) ? ['class' => $class, 'arguments' => $arguments] : null;
    }

    /**
     * Whether the class declares this property at all — which is also whether spatie will serialise it,
     * because `DataClassFactory` builds its property set from `ReflectionClass::getProperties()` and
     * nothing else. A name this build learned from a class-level `@property` tag reaches no payload.
     */
    public function declaresProperty(string $fqcn, string $property): bool
    {
        return $this->property($fqcn, $property) !== null;
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
