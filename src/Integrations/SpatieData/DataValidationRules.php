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
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Integrations\Support\DependencyFileSet;
use Docuccino\Laravel\Integrations\Support\FieldPaths;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\Transformers\AdditionalPropertiesRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\DateWireRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\UnreadRule;

/**
 * Derives a request {@see RuleSet} from a Data class so the shared validation chain documents the
 * body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest. Each property
 * contributes a presence rule, `nullable` when the type admits null, a base type rule from the
 * marker-stripped type (unless a spatie type attribute already stated one), and every recovered
 * validation token. Nested Data recurses into dotted `author.name` / `items.*.title` keys, and the input
 * key honours `#[MapInputName]`/`#[MapName]`. A property that can never be sent — `#[Computed]`,
 * `#[WithoutValidation]`, `#[FromRouteParameter]`, `#[HiddenFromRequest]`, or a `#[Prohibited]` whose
 * condition settles to it — contributes no field and no subtree.
 *
 * The rule vocabulary has one word — `array` — for every array shape, so a recovered container states its
 * own structure too: a `list<V>` synthesises the `key.*` item field Laravel itself uses, an `array{…}`
 * shape a `key.<member>` field per key, and an `array<string, V>` carries its value schema on an
 * `additional_properties` rule ({@see AdditionalPropertiesRuleTransformer}). The `array` word itself stays,
 * as laravel-data adds it, because Laravel reads an `in` or `not_in` beside it differently.
 *
 * A date is the same shape of gap: `date` is one word for everything non-relative `strtotime` parses, so a
 * `DateTimeInterface`-typed property states its own wire format on a `date_wire` rule
 * ({@see DateWireRuleTransformer}) — see {@see dateWireRules()} for where that format comes from.
 *
 * A static `rules()` override wins per field: spatie's `DataValidationRulesResolver` `add`s it at the
 * field key, REPLACING the inferred set rather than merging — unless the class carries
 * `#[MergeValidationRules]`, which makes the same resolver append instead. {@see DataRequestExtension}
 * recovers the override via {@see RulesFromClass} and passes it to {@see build()}.
 *
 * Both carriers state a fact about the DECLARED TYPE rather than about the rules, so an override that
 * restates only the vocabulary's coarse word — `array`, `date` — or names no type at all has not replaced
 * one, and it is re-attached ({@see withMapCarrier()}, {@see withDateCarrier()}). An override stating a
 * shape of its own has.
 */
final class DataValidationRules
{
    /**
     * The rules of spatie's `RequiringRule` attributes. Any one of them replaces the `required` a
     * property's type implies, so a property stating a conditional one is not required outright.
     */
    private const REQUIRING_RULES = ['required', 'required_if', 'required_unless', 'required_with', 'required_with_all', 'required_without', 'required_without_all'];

    /** Rule names that already fix a type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array', 'additional_properties'];

    /** A property of this type IS a file upload, whatever its rules() recovered. */
    private const UPLOADED_FILE = 'Illuminate\\Http\\UploadedFile';

    /** File-implying rules — a property already stating one never gets a synthesised second. */
    private const FILE_RULES = ['file', 'image'];

    private readonly DependencyFileSet $dependencyFiles;

    /**
     * The rule objects the last rule set could not read ({@see unreadRuleObjects()}).
     *
     * @var list<array{key: ?string, class: string, property: string, rule: string, narrows: bool}>
     */
    private array $unread = [];

    /** The collaborators one build runs against, set at the entry points below alongside them. */
    private ?TypeEngine $engine = null;

    private ?SchemaContext $schema = null;

    private ?ValidationRulesToSchema $validation = null;

    private readonly ReadDate $dates;

    /**
     * @param  string|list<string>  $dateFormat  the app's `data.date_format`, which the date cast parses input
     *                                           with where the property names no format of its own
     * @param  array<string, string>  $casts  the app's `data.casts` ({@see ReadDate})
     */
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
        private readonly RuleSetNormalizer $normalizer = new RuleSetNormalizer,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        string|array $dateFormat = DateWireFormat::DEFAULT_FORMAT,
        array $casts = ReadDate::DEFAULT_CASTS,
    ) {
        $this->dependencyFiles = new DependencyFileSet;
        $this->dates = new ReadDate($this->reflector, $dateFormat, $casts);
    }

    public function reflector(): DataClassReflector
    {
        return $this->reflector;
    }

    /**
     * Every file the last rule set was built from beyond the root class's own — an annotated rule class,
     * a nested Data class's declaration, an enum whose backing values were quoted into a rule. Recorded
     * by the caller so editing any of them invalidates the fragment. Reset on each entry point below.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->dependencyFiles->all();
    }

    /**
     * The rule objects the last {@see build()} saw and could not read, each with the request
     * key it was found at — null inside a map's value, which has no key of its own — the Data class and
     * property that carry it, and whether it can only refuse values. One that can is left out and the
     * property's other rules stand; one that cannot ({@see UnreadRule}) takes the property's stated rules
     * with it, leaving what its type says. A key a `rules()` override replaced was never enforced, so it
     * is not reported.
     *
     * @return list<array{key: ?string, class: string, property: string, rule: string, narrows: bool}>
     */
    public function unreadRuleObjects(): array
    {
        return $this->unread;
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
        $inferred = $this->fieldsFor($fqcn, $metadata, '', [$fqcn]);
        if ($overrides === null) {
            return new RuleSet(self::withObjectValues($inferred));
        }

        // Overwrite, not merge: the override replaces the inferred set at its key, and may name fields
        // no property inferred at all. `#[MergeValidationRules]` is the class-level opt-out spatie's own
        // resolver reads, and appends instead.
        $merging = $this->reflector->mergesValidationRules($fqcn);
        if (! $merging) {
            $this->unread = array_values(array_filter(
                $this->unread,
                static fn (array $unread): bool => $unread['key'] === null || ! isset($overrides->fields[$unread['key']]),
            ));
        }

        $fields = $inferred;
        // An override adds keys and replaces values, never removes one, so the sibling keys the carrier
        // consults are the same before the loop as after it.
        $siblings = [...array_keys($inferred), ...array_keys($overrides->fields)];

        foreach ($overrides->fields as $field => $rules) {
            $previous = $inferred[$field] ?? [];
            $fields[$field] = $merging
                ? [...$previous, ...$rules]
                : self::withDateCarrier(self::withMapCarrier($rules, $previous, $field, $siblings), $previous);
        }

        return new RuleSet(self::withObjectValues($fields));
    }

    /**
     * A replaced field's rules, with the recovered map's `additional_properties` carrier put back when the
     * override said no more about the container than the class docblock's rule allows. Left alone when the
     * override states the shape itself: another type word, or a named child, whose keys say more than open
     * values do.
     *
     * A `.*` child is NOT such a statement. Laravel applies a `field.*` rule to every value whatever the
     * keys are, so it carries no information about key type and cannot decide list-vs-map; it constrains
     * the value, and {@see withObjectValues()} is where the value's own container is settled.
     *
     * @param  list<ValidationRule>  $rules  the override's rules, now at the key
     * @param  list<ValidationRule>  $inferred  what property inference had put there
     * @param  list<string>  $siblings  every field key in the merged set
     * @return list<ValidationRule>
     */
    private static function withMapCarrier(array $rules, array $inferred, string $field, array $siblings): array
    {
        $carrier = self::carrier($inferred, 'additional_properties');

        // `array` has to be the only type word the override states, or none at all — an override stating
        // nothing contradicts nothing, so the recovered map is still all the information there is. `list`
        // narrows `array` to a JSON array and anything else has replaced the shape outright; an
        // `additional_properties` of its own needs no second.
        $stated = self::statedTypes($rules);
        if ($carrier === null || ($stated !== [] && $stated !== ['array']) || FieldPaths::hasNamedChild($field, $siblings)) {
            return $rules;
        }

        return [...$rules, $carrier];
    }

    /**
     * The same rule, one level down: a map's `.*` field, with the `array` word it can only have said
     * traded for the `object` the recovered VALUE schema states. Without it a value the type says is an
     * object publishes as a JSON array, and its size bound as a length.
     *
     * @param  array<string, list<ValidationRule>>  $fields
     * @return array<string, list<ValidationRule>>
     */
    private static function withObjectValues(array $fields): array
    {
        foreach (array_keys($fields) as $key) {
            $field = (string) $key;
            $wildcard = $field.'.*';
            if (! isset($fields[$wildcard]) || ! self::hasObjectValues($fields[$field])) {
                continue;
            }

            $fields[$wildcard] = self::withObjectWord($fields[$wildcard]);
        }

        return $fields;
    }

    /**
     * Whether these rules carry a map whose value schema is itself an object.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function hasObjectValues(array $rules): bool
    {
        $carrier = self::mapCarrier($rules);
        $json = $carrier?->parameter();
        if ($json === null) {
            return false;
        }

        $decoded = json_decode($json, true);
        $type = is_array($decoded) ? $decoded['type'] ?? null : null;

        return $type === 'object' || (is_array($type) && in_array('object', $type, true));
    }

    /**
     * The rules with `array` traded for `object`, when `array` is the ONLY container word they state — a
     * rewrite rather than an addition, so the word the size rules read stays where the coarse one was.
     *
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private static function withObjectWord(array $rules): array
    {
        if (self::statedTypes($rules) !== ['array']) {
            return $rules;
        }

        return array_map(
            static fn (ValidationRule $rule): ValidationRule => $rule->name === 'array' ? ValidationRule::of('object') : $rule,
            $rules,
        );
    }

    /**
     * The last `additional_properties` carrier in a rule list, or null where there is none.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function mapCarrier(array $rules): ?ValidationRule
    {
        $carrier = null;
        foreach ($rules as $rule) {
            if ($rule->name === 'additional_properties') {
                $carrier = $rule;
            }
        }

        return $carrier;
    }

    /**
     * A replaced field's rules with the recovered `date_wire` carrier put back when the override left the
     * wire format to be worked out — the class docblock's rule again: restating `date`, one word for
     * everything non-relative `strtotime` parses, or naming no type at all, has not replaced it. An
     * override carrying a `date_format`, or a type that is not a date string, has.
     *
     * @param  list<ValidationRule>  $rules  the override's rules, now at the key
     * @param  list<ValidationRule>  $inferred  what property inference had put there
     * @return list<ValidationRule>
     */
    private static function withDateCarrier(array $rules, array $inferred): array
    {
        $carrier = self::carrier($inferred, 'date_wire');
        if ($carrier === null || array_intersect(['date_format', 'date_wire'], self::ruleNames($rules)) !== []) {
            return $rules;
        }

        $stated = self::statedTypes($rules);

        return $stated === [] || $stated === ['string'] ? [...$rules, $carrier] : $rules;
    }

    /**
     * The LAST rule of a name in a set, or null. Last because that is the one the chain would apply, and
     * one loop because both carriers above ask the same question of the same shape of list.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function carrier(array $rules, string $name): ?ValidationRule
    {
        $carrier = null;
        foreach ($rules as $rule) {
            if ($rule->name === $name) {
                $carrier = $rule;
            }
        }

        return $carrier;
    }

    /**
     * The type words a rule set states, in table order — what an override has to have said for a recovered
     * carrier to be redundant.
     *
     * @param  list<ValidationRule>  $rules
     * @return list<string>
     */
    private static function statedTypes(array $rules): array
    {
        return array_values(array_intersect([...self::TYPE_RULES, ...self::FILE_RULES, 'list'], self::ruleNames($rules)));
    }

    /**
     * @param  list<ValidationRule>  $rules
     * @return list<string>
     */
    private static function ruleNames(array $rules): array
    {
        return array_map(static fn (ValidationRule $rule): string => $rule->name, $rules);
    }

    /** Resets the per-build state every entry point above starts from. */
    private function begin(TypeEngine $engine, ?SchemaContext $schema, ?ValidationRulesToSchema $validation): void
    {
        $this->dependencyFiles->clear();
        $this->unread = [];
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
            if ($this->reflector->isExcludedFromRequest($fqcn, $property->name)) {
                continue;
            }

            // A prohibited property contributes no field and no subtree. The rule set's `prohibited` pass
            // can't cover it: the nested-Data branch below never reaches the token.
            if ($this->statesProhibited($fqcn, $property->name)) {
                continue;
            }

            $key = $prefix.$this->reflector->inputName($fqcn, $property->name);
            $stripped = DataSchema::stripMarkers($property->type);

            $nested = $this->nestedData($fqcn, $property->name, self::unwrapNull($stripped), $visiting);
            if ($nested !== null) {
                [$childFqcn, $isCollection, $childMetadata] = $nested;
                // laravel-data writes the child's rules whatever presence rule the property states, so a child
                // field it requires requires the property too: its presence is its type's.
                $fields[$key] = $this->presence($fqcn, $property->name, $stripped, [], $isCollection ? 'array' : null, false);
                $fields = [...$fields, ...$this->fieldsFor($childFqcn, $childMetadata, $key.($isCollection ? '.*.' : '.'), [...$visiting, $childFqcn])];

                continue;
            }

            [$tokens, $objects] = $this->reflector->attributeRules($fqcn, $property->name, rtrim($prefix, '.'));
            $this->dependencyFiles->add(...$this->reflector->validationEnumFiles($fqcn, $property->name));
            [$objectRules, $opaque] = $this->ruleObjectRules($objects, $fqcn, $property->name, $key);
            $stated = $opaque ? [] : [
                ...array_map(RuleParsing::token(...), $tokens),
                ...$objectRules,
            ];
            $attributeRules = [
                ...$stated,
                ...$this->mapRules(self::unwrapNull($stripped), $visiting),
                ...$this->dateWireRules($fqcn, $property->name, $stripped, $stated),
            ];

            $requiring = $this->reflector->statesRequiringRule($fqcn, $property->name);

            // An UploadedFile-typed property gets a synthesised `file` rule so the shared chain flips the
            // body to multipart/form-data and emits a binary schema — needed because a real upload Data
            // class usually has a dynamic rules() and only `#[Required]` to go on.
            $upload = $this->uploadedFileKind($stripped, $attributeRules);
            if ($upload === 'single') {
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null, $requiring), ...$attributeRules, ValidationRule::of('file')];

                continue;
            }
            if ($upload === 'list') {
                // The field is the array; each item is the uploaded file.
                $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, 'array', $requiring), ...$attributeRules];
                $fields[$key.'.*'] = [ValidationRule::of('file')];

                continue;
            }

            $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null, $requiring), ...$attributeRules];
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
            $enum = $this->enumRule($inner);
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
     * The `date_wire` carrier for a `DateTimeInterface`-typed property: the formats the cast laravel-data
     * reads it with accepts ({@see ReadDate}) — a `#[WithCast(DateTimeInterfaceCast::class, format: …)]`'s
     * own, else the app's `data.date_format`, either a pattern or a list. The response side reads what the
     * transformer writes instead ({@see WrittenDate}), so the two agree wherever the class states neither.
     * A field whose own rules already carry a `date_format` says it more specifically still and gets none,
     * and so does one read by a cast no one can read: it claims no type it cannot stand behind.
     *
     * The PHP patterns travel on the rule, never a word summarising them: what the document may CLAIM about
     * a pattern — a `format` keyword, a note, the example bytes — is one policy, and
     * {@see DateWireRuleTransformer} applies it.
     *
     * @param  list<ValidationRule>  $stated  the rules the property itself declared
     * @return list<ValidationRule>
     */
    private function dateWireRules(string $fqcn, string $property, DType $stripped, array $stated): array
    {
        $dateClass = DataSchema::dateClass($stripped);
        if ($dateClass === null) {
            return [];
        }

        foreach ($stated as $rule) {
            if ($rule->name === 'date_format') {
                return [];
            }
        }

        $patterns = $this->dates->patterns($fqcn, $property, $dateClass);

        return $patterns === null ? [] : [ValidationRule::of('date_wire', $patterns)];
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
        $validation = $this->validation;
        $schema = $this->schema;
        $engine = $this->engine;
        if ($validation === null || $schema === null || $engine === null) {
            return null;
        }

        if (! $type instanceof ClassT || ! DataClassReflector::isData($type->fqcn) || in_array($type->fqcn, $visiting, true)) {
            return null;
        }

        $metadata = $engine->classMetadata(new ClassRef($type->fqcn));
        // A nested Data class's shape is as much a part of this rule set as the root's, so the files it
        // was recovered from are as much a dependency.
        $this->dependencyFiles->add(...$metadata->dependencyFiles);

        $before = count($this->unread);
        $fields = $this->fieldsFor($type->fqcn, $metadata, '', [...$visiting, $type->fqcn]);
        // A map's values have no key of their own, so what was found inside one is addressed by class alone.
        for ($index = $before; $index < count($this->unread); $index++) {
            $this->unread[$index]['key'] = null;
        }

        return $validation->convert(
            $this->ordering->order($this->normalizer->normalize(new RuleSet($fields))),
            $schema,
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
     * Whether a `#[Prohibited]` on the property prohibits it outright: bare, or handed a `ProhibitedIf` whose
     * condition settles to it, read as {@see ruleObjectRules()} reads the object. One on a condition this build
     * cannot settle leaves the property sendable.
     */
    private function statesProhibited(string $fqcn, string $property): bool
    {
        foreach ($this->reflector->prohibitions($fqcn, $property) as $rule) {
            $read = is_string($rule) ? [RuleParsing::token($rule)] : (new ConstValueToRules($this->customRules))->fold(LiveRuleValue::of($rule));
            if (in_array('prohibited', array_map(static fn (ValidationRule $rule): string => $rule->name, $read), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rules from the rule objects a property's attributes hand Laravel ({@see DataClassReflector::attributeRules()}),
     * read as the same `new` in a rules array reads ({@see ConstValueToRules}): a framework object as the
     * `Rule::` factory that builds it, an annotated one by its `#[RuleSchema]`. Also whether one of them has
     * content nothing can read that could loosen the rules beside it. One that does not read is recorded
     * ({@see unreadRuleObjects()}).
     *
     * @param  list<object>  $objects
     * @return array{0: list<ValidationRule>, 1: bool}
     */
    private function ruleObjectRules(array $objects, string $fqcn, string $property, string $key): array
    {
        $rules = [];
        $opaque = false;
        foreach ($objects as $object) {
            $folder = new ConstValueToRules($this->customRules);
            $read = $folder->fold(LiveRuleValue::of($object));
            $this->dependencyFiles->add(...$folder->dependencyFiles());

            $losses = array_map(static fn (string $label): array => [$label, true], $folder->unread());
            if ($folder->opaque() !== null) {
                $losses[] = [$folder->opaque(), false];
            }

            // A choice whose values did not all read publishes none of them, which the reader owes a word too.
            if ($folder->widened()) {
                $losses[] = [UnreadRule::classify($object::class)['label'], true];
            }

            foreach ($losses as [$label, $narrows]) {
                $opaque = $opaque || ! $narrows;
                $this->unread[] = ['key' => $key, 'class' => $fqcn, 'property' => $property, 'rule' => $label, 'narrows' => $narrows];
            }

            $rules = [...$rules, ...$read];
        }

        return [$rules, $opaque];
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

        $metadata = $this->engine->classMetadata(new ClassRef($childFqcn));
        $this->dependencyFiles->add(...$metadata->dependencyFiles);

        return [$childFqcn, $isCollection, $metadata];
    }

    /**
     * Presence/nullability/type rules synthesised from the property type, prepended ahead of the spatie
     * attribute rules and only when one doesn't already state them. Mirrors spatie's own inference:
     * `required` is skipped for a nullable, Optional/Lazy or defaulted property, for one stating any
     * requiring rule ({@see REQUIRING_RULES}), and where `$requiring` says it carries a requiring attribute
     * ({@see DataClassReflector::statesRequiringRule()}), whatever rule that attribute builds.
     *
     * @param  list<ValidationRule>  $attributeRules
     * @return list<ValidationRule>
     */
    private function presence(string $fqcn, string $property, DType $stripped, array $attributeRules, ?string $forceType, bool $requiring): array
    {
        $named = array_map(static fn (ValidationRule $rule): string => $rule->name, $attributeRules);
        $out = [];

        $optional = $this->reflector->isPropertyOptional($fqcn, $property);
        $defaulted = $this->reflector->propertyDefault($fqcn, $property)['hasDefault'];
        $nullable = $stripped instanceof UnionT && $stripped->containsNull();

        if (($optional || $defaulted) && ! in_array('sometimes', $named, true)) {
            $out[] = ValidationRule::of('sometimes');
        } elseif (! $optional && ! $defaulted && ! $nullable && ! $requiring
            && array_intersect(self::REQUIRING_RULES, $named) === [] && ! in_array('present', $named, true)) {
            $out[] = ValidationRule::of('required');
        }

        if ($nullable && ! in_array('nullable', $named, true)) {
            $out[] = ValidationRule::of('nullable');
        }

        if ($forceType !== null) {
            $out[] = ValidationRule::of($forceType);

            return $out;
        }

        // laravel-data adds `array` to every array-typed property, and `in`/`not_in` read the elements beside it.
        if (self::isContainer($stripped) && ! in_array('array', $named, true)) {
            $out[] = ValidationRule::of('array');
        }

        $enum = $this->enumRule($stripped);
        if ($enum !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = $enum;

            return $out;
        }

        // A keyed shape's word is the `array` already carried.
        $typeRule = self::typeRule($stripped);
        if ($typeRule !== null && $typeRule !== 'array' && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = ValidationRule::of($typeRule);
        }

        return $out;
    }

    /**
     * An `enum` rule carrying the backing values plus the FQCN, for an enum-typed property. The backing
     * VALUES go into the rule, so the enum's file is a dependency of the rule set that quotes them.
     */
    private function enumRule(DType $stripped): ?ValidationRule
    {
        $type = self::unwrapNull($stripped);
        if (! $type instanceof EnumT) {
            return null;
        }

        $this->dependencyFiles->add(EnumReflection::file($type->fqcn));

        $values = array_map(strval(...), EnumReflection::values($type->fqcn));

        return $values === [] ? null : ValidationRule::of('enum', $values, $type->fqcn);
    }

    /**
     * The rule word for a recovered type. A container type says which container it is — `list` for proven
     * sequential keys, `object` for a string-keyed map — beyond the `array` the vocabulary uses for both:
     * `array` alone would hand the rule chain an open question this recovery had already answered, and a
     * shape contributing no child path to answer it with (a positional tuple, a map whose values nothing
     * could describe) would be widened to "array or object" on the way out. Only a keyed array SHAPE says
     * `array`, because the child paths it contributes settle it.
     */
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

        if ($type instanceof ListT) {
            return 'list';
        }

        if ($type instanceof ArrayShapeT) {
            return $type->isList ? 'list' : 'array';
        }

        return $type instanceof MapT ? 'object' : null;
    }

    /** Whether a type is a PHP array, whatever shape it was recovered as. */
    private static function isContainer(DType $type): bool
    {
        $type = self::unwrapNull($type);

        return $type instanceof ListT || $type instanceof MapT || $type instanceof ArrayShapeT;
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
