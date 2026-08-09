<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
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

/**
 * Derives a request {@see RuleSet} from a Data class so the shared validation chain documents the
 * body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest. Each property
 * contributes a presence rule, `nullable` when the type admits null, a base type rule from the
 * marker-stripped type (unless a spatie type attribute already stated one), and every recovered
 * validation token. Nested Data recurses into dotted `author.name` / `items.*.title` keys, and the input
 * key honours `#[MapInputName]`/`#[MapName]`.
 *
 * A static `rules()` override wins per field: spatie's `DataValidationRulesResolver` `add`s it at the
 * field key, REPLACING the inferred set rather than merging. {@see DataRequestExtension} recovers it
 * via {@see RulesFromClass} and passes it to {@see build()}.
 */
final class DataValidationRules
{
    /** Rule names that already fix a scalar type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array'];

    /** A property of this type IS a file upload, whatever its rules() recovered. */
    private const UPLOADED_FILE = 'Illuminate\\Http\\UploadedFile';

    /** File-implying rules — a property already stating one never gets a synthesised second. */
    private const FILE_RULES = ['file', 'image'];

    /**
     * @var list<string>
     */
    private array $dependencyFiles = [];

    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
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
        $this->dependencyFiles = [];

        return array_keys($this->fieldsFor($fqcn, $metadata, $engine, '', [$fqcn]));
    }

    public function build(string $fqcn, ClassMetadata $metadata, TypeEngine $engine, ?RuleSet $overrides = null): RuleSet
    {
        $this->dependencyFiles = [];
        $fields = $this->fieldsFor($fqcn, $metadata, $engine, '', [$fqcn]);

        // Overwrite, not merge: the override replaces the inferred set at its key, and may name fields
        // no property inferred at all.
        if ($overrides !== null) {
            foreach ($overrides->fields as $field => $rules) {
                $fields[$field] = $rules;
            }
        }

        return new RuleSet($fields);
    }

    /**
     * @param  list<string>  $visiting  the recursion chain of Data FQCNs (cycle guard)
     * @return array<string, list<ValidationRule>>
     */
    private function fieldsFor(string $fqcn, ClassMetadata $metadata, TypeEngine $engine, string $prefix, array $visiting): array
    {
        $fields = [];
        foreach ($metadata->properties as $property) {
            if ($this->reflector->isExcludedFromRequest($fqcn, $property->name)) {
                continue;
            }

            $key = $prefix.$this->reflector->inputName($fqcn, $property->name);
            $stripped = DataSchema::stripMarkers($property->type);

            $nested = $this->nestedData($fqcn, $property->name, self::unwrapNull($stripped), $engine, $visiting);
            if ($nested !== null) {
                [$childFqcn, $isCollection, $childMetadata] = $nested;
                $fields[$key] = $this->presence($fqcn, $property->name, $stripped, [], $isCollection ? 'array' : null);
                $fields = [...$fields, ...$this->fieldsFor($childFqcn, $childMetadata, $engine, $key.($isCollection ? '.*.' : '.'), [...$visiting, $childFqcn])];

                continue;
            }

            $tokens = $this->reflector->validationTokens($fqcn, $property->name);
            $attributeRules = [
                ...array_map(RuleParsing::token(...), $tokens),
                ...$this->ruleObjectRules($fqcn, $property->name),
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
        }

        return $fields;
    }

    /**
     * Rules from a `#[Rule(new Iban)]` object's `#[RuleSchema]`, alongside the string tokens. An
     * unannotated rule object contributes nothing, exactly as before.
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
    private function nestedData(string $fqcn, string $property, DType $stripped, TypeEngine $engine, array $visiting): ?array
    {
        // `#[DataCollectionOf(SongData::class)]` names the item class with no docblock generic at all.
        $declared = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($declared !== null && DataClassReflector::isData($declared)) {
            return $this->descend($declared, true, $engine, $visiting);
        }

        if ($stripped instanceof ListT && $stripped->value instanceof ClassT && DataClassReflector::isData($stripped->value->fqcn)) {
            return $this->descend($stripped->value->fqcn, true, $engine, $visiting);
        }

        if ($stripped instanceof ClassT && DataClassReflector::isDataCollection($stripped->fqcn)) {
            $item = DataClassReflector::collectionValueType($stripped);

            return $item instanceof ClassT && DataClassReflector::isData($item->fqcn)
                ? $this->descend($item->fqcn, true, $engine, $visiting)
                : null;
        }

        if ($stripped instanceof ClassT && DataClassReflector::isData($stripped->fqcn)) {
            return $this->descend($stripped->fqcn, false, $engine, $visiting);
        }

        return null;
    }

    /**
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function descend(string $childFqcn, bool $isCollection, TypeEngine $engine, array $visiting): ?array
    {
        if (in_array($childFqcn, $visiting, true)) {
            return null;
        }

        return [$childFqcn, $isCollection, $engine->classMetadata(new ClassRef($childFqcn))];
    }

    /**
     * Presence/nullability/type rules synthesised from the property type, prepended ahead of the spatie
     * attribute rules and only when one doesn't already state them. Mirrors Laravel's own inference:
     * `required` is skipped for a nullable, Optional/Lazy, defaulted or prohibited property.
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
        $prohibited = $this->reflector->isProhibited($fqcn, $property);

        if (($optional || $defaulted) && ! in_array('sometimes', $named, true)) {
            $out[] = ValidationRule::of('sometimes');
        } elseif (! $optional && ! $defaulted && ! $nullable && ! $prohibited
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

        $enum = $this->enumRule($stripped);
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
    private function enumRule(DType $stripped): ?ValidationRule
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
