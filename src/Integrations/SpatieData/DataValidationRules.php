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

/**
 * Derives a request {@see RuleSet} from a Data class so the SHARED validation chain documents the
 * request body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest.
 * Each property contributes: a presence rule (`required`, or `sometimes` for an `Optional`/`Lazy`
 * marker or a defaulted property), `nullable` when the type admits null, a base type rule inferred
 * from the (marker-stripped) property type (unless a spatie type attribute already stated one; an
 * enum type contributes its backing values), and every recovered spatie validation token
 * ({@see DataClassReflector::validationTokens()}). Nested Data / Data-collection properties recurse
 * into dotted `author.name` / `items.*.title` rules. The input key honours `#[MapInputName]`/`#[MapName]`
 * (incl. mapper classes). `#[Computed]`/`#[WithoutValidation]` properties are excluded.
 *
 * A static `rules()` override on the Data class WINS per field over the inferred rules (spatie's
 * `DataValidationRulesResolver` `add`s the override at the field key, replacing the inferred set); it
 * is read via the engine's literal + descriptor analysis ({@see RulesFromClass})
 * and passed to {@see build()} by {@see DataRequestExtension}.
 */
final class DataValidationRules
{
    /** Rule names that already fix a scalar type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array'];

    /** A property of this type (or a subclass) IS a file upload, whatever its rules() recovered. */
    private const UPLOADED_FILE = 'Illuminate\\Http\\UploadedFile';

    /** File-implying rule names — if a property already states one, we never synthesise a second. */
    private const FILE_RULES = ['file', 'image'];

    public function __construct(private readonly DataClassReflector $reflector = new DataClassReflector) {}

    public function reflector(): DataClassReflector
    {
        return $this->reflector;
    }

    /**
     * The request field keys recovered from the class's PROPERTIES alone (before any rules() override),
     * so a caller can tell which fields the property inference documents — e.g. to suppress a stale
     * `validation.rule-unrecoverable` for a field whose rules() is dynamic but whose type (an
     * `UploadedFile`) already documents it.
     *
     * @return list<string>
     */
    public function propertyFieldKeys(string $fqcn, ClassMetadata $metadata, TypeEngine $engine): array
    {
        return array_keys($this->fieldsFor($fqcn, $metadata, $engine, '', [$fqcn]));
    }

    public function build(string $fqcn, ClassMetadata $metadata, TypeEngine $engine, ?RuleSet $overrides = null): RuleSet
    {
        $fields = $this->fieldsFor($fqcn, $metadata, $engine, '', [$fqcn]);

        // A static rules() override replaces the inferred rules per field (spatie's default `add`
        // semantics, not merge) and may declare fields no property inferred — both are honoured by
        // overwriting/appending at the override's key.
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
            $attributeRules = array_map(RuleParsing::token(...), $tokens);

            // A property typed Illuminate\Http\UploadedFile (incl. ?UploadedFile and a list of it) IS a
            // file upload — synthesise a `file` rule so the shared validation chain flips the body to
            // multipart/form-data and emits a binary schema, regardless of whether the class's rules()
            // was statically foldable (a real CreateUploadData often has a dynamic rules() and only #[Required]).
            // Composes with an explicit file/image rule rather than doubling it.
            $upload = $this->uploadedFileKind($stripped, $attributeRules);
            if ($upload === 'single') {
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null), ...$attributeRules, ValidationRule::of('file')];

                continue;
            }
            if ($upload === 'list') {
                // Each item is the uploaded file; the field itself is the (multipart) array.
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, 'array'), ...$attributeRules];
                $fields[$key.'.*'] = [ValidationRule::of('file')];

                continue;
            }

            $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null), ...$attributeRules];
        }

        return $fields;
    }

    /**
     * Classify a property type as a file upload: `'single'` for `UploadedFile` / `?UploadedFile`,
     * `'list'` for a list of `UploadedFile`, else null. Returns null when the property already carries
     * a file-implying rule (`file`/`image`) so the synthesised rule never doubles an explicit one.
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
     * The item Data class (and whether it is a collection) a nested-Data property recurses into, or
     * null when the property is not a nested Data / Data-collection. Guards against cycles.
     *
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function nestedData(string $fqcn, string $property, DType $stripped, TypeEngine $engine, array $visiting): ?array
    {
        // #[DataCollectionOf(SongData::class)] — the item class named explicitly (no docblock generic).
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
     * The presence/nullability/type rules synthesised from the property type, prepended ahead of any
     * spatie attribute rules and only when not already stated by one. `required` is skipped for a
     * nullable, Optional/Lazy, defaulted or prohibited property (Laravel's own rule inference).
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

    /** An `enum` rule (backing values + FQCN note) for an enum-typed property, else null. */
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
