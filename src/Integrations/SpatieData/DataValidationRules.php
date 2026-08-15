<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\ValidationRulesToSchema;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\Transformers\AdditionalPropertiesRuleTransformer;

/**
 * Derives a request {@see RuleSet} from a Data class so the shared validation chain documents the
 * body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest. Each property
 * contributes a presence rule, `nullable` when the type admits null, a base type rule from the
 * marker-stripped type (unless a spatie type attribute already stated one), and every recovered
 * validation token. Nested Data recurses into dotted `author.name` / `items.*.title` keys, and the input
 * key honours `#[MapInputName]`/`#[MapName]`. A property that can never be sent — `#[Computed]`,
 * `#[WithoutValidation]`, `#[FromRouteParameter]`, `#[HiddenFromRequest]`, `#[Prohibited]` — contributes
 * no field and no subtree.
 *
 * The rule vocabulary has one word — `array` — for every array shape, so a recovered container states its
 * own structure instead: a `list<V>` synthesises the `key.*` item field Laravel itself uses, an `array{…}`
 * shape a `key.<member>` field per key, and an `array<string, V>` carries its value schema on an
 * `additional_properties` rule ({@see AdditionalPropertiesRuleTransformer}).
 *
 * A static `rules()` override wins per field: spatie's `DataValidationRulesResolver` `add`s it at the
 * field key, REPLACING the inferred set rather than merging. {@see DataRequestExtension} recovers it
 * via {@see RulesFromClass} and passes it to {@see build()}.
 */
final class DataValidationRules
{
    /** Rule names that already fix a type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array', 'additional_properties'];

    /** A property of this type IS a file upload, whatever its rules() recovered. */
    private const UPLOADED_FILE = 'Illuminate\\Http\\UploadedFile';

    /** File-implying rules — a property already stating one never gets a synthesised second. */
    private const FILE_RULES = ['file', 'image'];

    /**
     * @var list<string>
     */
    private array $dependencyFiles = [];

    /** The collaborators one build runs against, set at the entry points below alongside them. */
    private ?TypeEngine $engine = null;

    private ?SchemaContext $schema = null;

    private ?ValidationRulesToSchema $validation = null;

    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
        private readonly RuleSetNormalizer $normalizer = new RuleSetNormalizer,
        private readonly RuleOrdering $ordering = new RuleOrdering,
    ) {}

    public function reflector(): DataClassReflector
    {
        return $this->reflector;
    }

    /**
     * Rule classes read while building the last rule set — recorded by the caller so editing an
     * annotated rule invalidates the fragment. Reset on each entry point below.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->dependencyFiles;
    }

    /**
     * The field keys from the class's properties alone, before any rules() override. Lets a caller tell
     * what property inference already documents — e.g. to suppress a `validation.rule-unrecoverable` for
     * a field whose rules() is dynamic but whose `UploadedFile` type documents it anyway.
     *
     * @return list<string>
     */
    public function propertyFieldKeys(string $fqcn, ClassMetadata $metadata, TypeEngine $engine): array
    {
        $this->begin($engine, null, null);

        return array_keys($this->fieldsFor($fqcn, $metadata, '', [$fqcn]));
    }

    /**
     * @param  SchemaContext|null  $schema  the type→schema chain, for the value schema of a recovered
     *                                      `array<string, V>` property; without it a map degrades to the
     *                                      bare `array` rule.
     * @param  ValidationRulesToSchema|null  $validation  the rule→schema chain, for the REQUEST schema of a
     *                                                    Data class reached as a map's value
     */
    public function build(string $fqcn, ClassMetadata $metadata, TypeEngine $engine, ?RuleSet $overrides = null, ?SchemaContext $schema = null, ?ValidationRulesToSchema $validation = null): RuleSet
    {
        $this->begin($engine, $schema, $validation);
        $fields = $this->fieldsFor($fqcn, $metadata, '', [$fqcn]);

        // Overwrite, not merge: the override replaces the inferred set at its key, and may name fields
        // no property inferred at all.
        if ($overrides !== null) {
            foreach ($overrides->fields as $field => $rules) {
                $fields[$field] = $rules;
            }
        }

        return new RuleSet($fields);
    }

    /** Resets the per-build state every entry point above starts from. */
    private function begin(TypeEngine $engine, ?SchemaContext $schema, ?ValidationRulesToSchema $validation): void
    {
        $this->dependencyFiles = [];
        $this->engine = $engine;
        $this->schema = $schema;
        $this->validation = $validation;
    }

    /**
     * @param  list<string>  $visiting  the recursion chain of Data FQCNs (cycle guard)
     * @return array<string, list<ValidationRule>>
     */
    private function fieldsFor(string $fqcn, ClassMetadata $metadata, string $prefix, array $visiting): array
    {
        $fields = [];
        foreach ($metadata->properties as $property) {
            // A prohibited property contributes no field and no subtree. The rule set's `prohibited` pass
            // can't cover it: the nested-Data branch below never reaches the token.
            if ($this->reflector->isExcludedFromRequest($fqcn, $property->name)
                || $this->reflector->isProhibited($fqcn, $property->name)) {
                continue;
            }

            $key = $prefix.$this->reflector->inputName($fqcn, $property->name);
            $stripped = DataSchema::stripMarkers($property->type);

            $nested = $this->nestedData($fqcn, $property->name, self::unwrapNull($stripped), $visiting);
            if ($nested !== null) {
                [$childFqcn, $isCollection, $childMetadata] = $nested;
                $fields[$key] = $this->presence($fqcn, $property->name, $stripped, [], $isCollection ? 'array' : null);
                $fields = [...$fields, ...$this->fieldsFor($childFqcn, $childMetadata, $key.($isCollection ? '.*.' : '.'), [...$visiting, $childFqcn])];

                continue;
            }

            $tokens = $this->reflector->validationTokens($fqcn, $property->name);
            $attributeRules = [
                ...array_map(RuleParsing::token(...), $tokens),
                ...$this->ruleObjectRules($fqcn, $property->name),
                ...$this->mapRules(self::unwrapNull($stripped), $visiting),
            ];

            // An UploadedFile-typed property gets a synthesised `file` rule so the shared chain flips the
            // body to multipart/form-data and emits a binary schema — needed because a real upload Data
            // class usually has a dynamic rules() and only `#[Required]` to go on.
            $upload = $this->uploadedFileKind($stripped, $attributeRules);
            if ($upload === 'single') {
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null), ...$attributeRules, ValidationRule::of('file')];

                continue;
            }
            if ($upload === 'list') {
                // The field is the array; each item is the uploaded file.
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, 'array'), ...$attributeRules];
                $fields[$key.'.*'] = [ValidationRule::of('file')];

                continue;
            }

            $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null), ...$attributeRules];
            $fields = [...$fields, ...$this->containerFields($key, self::unwrapNull($stripped), $visiting)];
        }

        return $fields;
    }

    /**
     * The child field paths a recovered container contributes: `key.*` for a list's items, `key.<member>`
     * for an array shape's keys, recursing so a nested container keeps its shape too. A map needs none —
     * its values are a schema, not a path. The descent terminates because a {@see DType} is a finite
     * acyclic tree.
     *
     * @param  list<string>  $visiting
     * @return array<string, list<ValidationRule>>
     */
    private function containerFields(string $key, DType $type, array $visiting): array
    {
        if ($type instanceof ListT) {
            return $this->childField($key.'.*', $type->value, [], $visiting);
        }

        // A positional shape is an array whose members differ per index, which no `key.*` rule can say;
        // it keeps the bare `array` rule.
        if (! $type instanceof ArrayShapeT || $type->isList) {
            return [];
        }

        $fields = [];
        foreach ($type->fields as $field) {
            $presence = [ValidationRule::of($field->optional ? 'sometimes' : 'required')];
            $fields = [...$fields, ...$this->childField($key.'.'.$field->key, $field->type, $presence, $visiting)];
        }

        return $fields;
    }

    /**
     * One synthesised child path plus whatever its own type contributes below it. A child nothing can be
     * said about still gets its path, so the parent renders `items: {}` / a `required` member rather than
     * an itemless array — the same answer the response side gives for the same type.
     *
     * @param  list<ValidationRule>  $presence
     * @param  list<string>  $visiting
     * @return array<string, list<ValidationRule>>
     */
    private function childField(string $key, DType $type, array $presence, array $visiting): array
    {
        $inner = self::unwrapNull($type);
        $rules = $this->mapRules($inner, $visiting);

        if ($rules === []) {
            $enum = self::enumRule($inner);
            $typeRule = self::typeRule($inner);
            $rules = match (true) {
                $enum !== null => [$enum],
                $typeRule !== null => [ValidationRule::of($typeRule)],
                default => [],
            };
        }

        $nullable = $type instanceof UnionT && $type->containsNull() ? [ValidationRule::of('nullable')] : [];

        return [$key => [...$presence, ...$nullable, ...$rules], ...$this->containerFields($key, $inner, $visiting)];
    }

    /**
     * The `additional_properties` carrier for a recovered `array<string, V>`: Laravel has no rule that
     * means "an object whose values look like this", so the value schema travels as JSON on a rule of our
     * own.
     *
     * @param  list<string>  $visiting
     * @return list<ValidationRule>
     */
    private function mapRules(DType $type, array $visiting): array
    {
        if (! $type instanceof MapT || $this->schema === null) {
            return [];
        }

        $json = json_encode($this->mapValueSchema($type, $visiting), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? [] : [ValidationRule::of('additional_properties', [$json])];
    }

    /**
     * The value schema a map carries. A Data-class value is built from the value class's OWN request fields
     * — the type→schema chain would run the RESPONSE mapper, which keys by `#[MapOutputName]` and publishes
     * exactly what `#[HiddenFromRequest]` exists to keep out of a request body — and a Data class anywhere
     * else in the value type gets an unconstrained schema for the same reason.
     *
     * @param  list<string>  $visiting
     * @return array<array-key, mixed>
     */
    private function mapValueSchema(MapT $type, array $visiting): array
    {
        $value = self::unwrapNull($type->value);

        $request = $this->requestObject($value, $visiting);
        if ($request !== null) {
            return $request;
        }

        if (self::mentionsData($value)) {
            return [];
        }

        // Converting the MAP rather than its value, because the chain's depth is what tells a nested
        // class it isn't a response root.
        $converted = $this->schema?->convert($type)['additionalProperties'] ?? [];

        return is_array($converted) ? $converted : [];
    }

    /**
     * The request schema of a Data class reached as a container value — the same field walk, normalise and
     * order the top level runs — or null when the type isn't one, the chain can't convert rules, or the
     * class is already being visited.
     *
     * @param  list<string>  $visiting
     * @return array<string, mixed>|null
     */
    private function requestObject(DType $type, array $visiting): ?array
    {
        if ($this->validation === null || $this->schema === null || $this->engine === null) {
            return null;
        }

        if (! $type instanceof ClassT || ! DataClassReflector::isData($type->fqcn) || in_array($type->fqcn, $visiting, true)) {
            return null;
        }

        $fields = $this->fieldsFor(
            $type->fqcn,
            $this->engine->classMetadata(new ClassRef($type->fqcn)),
            '',
            [...$visiting, $type->fqcn],
        );

        return $this->validation->convert(
            $this->ordering->order($this->normalizer->normalize(new RuleSet($fields))),
            $this->schema,
        )->schema;
    }

    /** Whether a Data class appears anywhere in a type, at any depth. */
    private static function mentionsData(DType $type): bool
    {
        return match (true) {
            $type instanceof ClassT => DataClassReflector::isData($type->fqcn)
                || DataClassReflector::isDataCollection($type->fqcn)
                || self::anyMentionsData($type->typeArgs),
            $type instanceof ListT, $type instanceof MapT => self::mentionsData($type->value),
            $type instanceof UnionT => self::anyMentionsData($type->members),
            $type instanceof ArrayShapeT => self::anyMentionsData(array_map(
                static fn (ArrayShapeField $field): DType => $field->type,
                $type->fields,
            )),
            default => false,
        };
    }

    /**
     * @param  list<DType>  $types
     */
    private static function anyMentionsData(array $types): bool
    {
        foreach ($types as $type) {
            if (self::mentionsData($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rules from a `#[Rule(new Iban)]` object's `#[RuleSchema]`, alongside the string tokens. An
     * unannotated rule object contributes nothing.
     *
     * @return list<ValidationRule>
     */
    private function ruleObjectRules(string $fqcn, string $property): array
    {
        $rules = [];
        foreach ($this->reflector->ruleObjectClasses($fqcn, $property) as $ruleClass) {
            $facts = $this->customRules->read($ruleClass);
            if ($facts->file !== null && ! in_array($facts->file, $this->dependencyFiles, true)) {
                $this->dependencyFiles[] = $facts->file;
            }

            $rules = [...$rules, ...$facts->rules];
        }

        return $rules;
    }

    /**
     * `'single'` for `UploadedFile`/`?UploadedFile`, `'list'` for a list of them, else null. Also null
     * when the property already states `file`/`image`, so we never double an explicit rule.
     *
     * @param  list<ValidationRule>  $attributeRules
     */
    private function uploadedFileKind(DType $stripped, array $attributeRules): ?string
    {
        foreach ($attributeRules as $rule) {
            if (in_array($rule->name, self::FILE_RULES, true)) {
                return null;
            }
        }

        $type = self::unwrapNull($stripped);

        if ($type instanceof ListT && self::isUploadedFile($type->value)) {
            return 'list';
        }

        return self::isUploadedFile($type) ? 'single' : null;
    }

    private static function isUploadedFile(DType $type): bool
    {
        return $type instanceof ClassT && is_a($type->fqcn, self::UPLOADED_FILE, true);
    }

    /**
     * `[item class, isCollection, metadata]` for a nested-Data property, else null. Cycle-guarded.
     *
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function nestedData(string $fqcn, string $property, DType $stripped, array $visiting): ?array
    {
        // `#[DataCollectionOf(SongData::class)]` names the item class with no docblock generic at all.
        $declared = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($declared !== null && DataClassReflector::isData($declared)) {
            return $this->descend($declared, true, $visiting);
        }

        if ($stripped instanceof ListT && $stripped->value instanceof ClassT && DataClassReflector::isData($stripped->value->fqcn)) {
            return $this->descend($stripped->value->fqcn, true, $visiting);
        }

        if ($stripped instanceof ClassT && DataClassReflector::isDataCollection($stripped->fqcn)) {
            $item = DataClassReflector::collectionValueType($stripped);

            return $item instanceof ClassT && DataClassReflector::isData($item->fqcn)
                ? $this->descend($item->fqcn, true, $visiting)
                : null;
        }

        if ($stripped instanceof ClassT && DataClassReflector::isData($stripped->fqcn)) {
            return $this->descend($stripped->fqcn, false, $visiting);
        }

        return null;
    }

    /**
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function descend(string $childFqcn, bool $isCollection, array $visiting): ?array
    {
        if ($this->engine === null || in_array($childFqcn, $visiting, true)) {
            return null;
        }

        return [$childFqcn, $isCollection, $this->engine->classMetadata(new ClassRef($childFqcn))];
    }

    /**
     * Presence/nullability/type rules synthesised from the property type, prepended ahead of the spatie
     * attribute rules and only when one doesn't already state them. Mirrors Laravel's own inference:
     * `required` is skipped for a nullable, Optional/Lazy or defaulted property.
     *
     * @param  list<ValidationRule>  $attributeRules
     * @return list<ValidationRule>
     */
    private function presence(string $fqcn, string $property, DType $stripped, array $attributeRules, ?string $forceType): array
    {
        $named = array_map(static fn (ValidationRule $rule): string => $rule->name, $attributeRules);
        $out = [];

        $optional = $this->reflector->isPropertyOptional($fqcn, $property);
        $defaulted = $this->reflector->propertyDefault($fqcn, $property)['hasDefault'];
        $nullable = $stripped instanceof UnionT && $stripped->containsNull();

        if (($optional || $defaulted) && ! in_array('sometimes', $named, true)) {
            $out[] = ValidationRule::of('sometimes');
        } elseif (! $optional && ! $defaulted && ! $nullable
            && ! in_array('required', $named, true) && ! in_array('present', $named, true)) {
            $out[] = ValidationRule::of('required');
        }

        if ($nullable && ! in_array('nullable', $named, true)) {
            $out[] = ValidationRule::of('nullable');
        }

        if ($forceType !== null) {
            $out[] = ValidationRule::of($forceType);

            return $out;
        }

        $enum = self::enumRule($stripped);
        if ($enum !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = $enum;

            return $out;
        }

        $typeRule = self::typeRule($stripped);
        if ($typeRule !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = ValidationRule::of($typeRule);
        }

        return $out;
    }

    /** An `enum` rule carrying the backing values plus the FQCN, for an enum-typed property. */
    private static function enumRule(DType $stripped): ?ValidationRule
    {
        $type = self::unwrapNull($stripped);
        if (! $type instanceof EnumT) {
            return null;
        }

        $values = array_map(strval(...), EnumReflection::values($type->fqcn));

        return $values === [] ? null : ValidationRule::of('enum', $values, $type->fqcn);
    }

    private static function typeRule(DType $type): ?string
    {
        $type = self::unwrapNull($type);

        if ($type instanceof ScalarT) {
            return match ($type->scalar) {
                ScalarT::INT => 'integer',
                ScalarT::FLOAT => 'numeric',
                ScalarT::BOOL => 'boolean',
                default => 'string',
            };
        }

        if ($type instanceof ListT || $type instanceof MapT || $type instanceof ArrayShapeT) {
            return 'array';
        }

        return null;
    }

    /** The sole non-null member of a nullable union, else the type itself. */
    private static function unwrapNull(DType $type): DType
    {
        if (! $type instanceof UnionT) {
            return $type;
        }

        $stripped = $type->without(static fn (DType $member): bool => $member instanceof NullT);

        return $stripped instanceof UnionT ? $type : $stripped;
    }
}
