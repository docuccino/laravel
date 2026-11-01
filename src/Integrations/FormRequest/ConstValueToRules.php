<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Laravel\Integrations\Support\DependencyFileSet;
use Docuccino\Laravel\Integrations\Support\EnumCaseValue;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;
use Docuccino\Laravel\Integrations\Validation\RuleFactories;
use Docuccino\Laravel\Integrations\Validation\RuleLosses;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\UnreadRule;

/**
 * Folds one field's statically-recovered rules — from an inline `validate([...])` or
 * `Validator::make(...)` — into {@see ValidationRule}s: pipe strings, array-of-rule forms, `Rule::*`
 * factory descriptors, the framework rule objects those factories build, and `new` rule objects whose
 * class carries a `#[RuleSchema]`. Descriptors are why folding happens at the AST level:
 * `Rule::enum(Status::class)` has to be caught as a call, before PHPStan collapses it to a bare object.
 *
 * A rule it can see but not read is never dropped quietly ({@see UnreadRule}): one that can only refuse
 * values is left out and named in {@see unread()}, and one whose content is unknown gives up the whole
 * field, named in {@see opaque()}, because the rules beside it may not hold.
 *
 * The rule classes it read come back as {@see dependencyFiles()} for the caller to record — editing an
 * annotated rule has to re-document every field using it. One folder per recovery, never shared.
 */
final class ConstValueToRules
{
    /** Framework rule objects that exclude the field when a condition holds, by the rule name each stands for. */
    private const EXCLUDE_OBJECTS = [
        'Illuminate\\Validation\\Rules\\ExcludeIf' => 'exclude_if',
        'Illuminate\\Validation\\Rules\\ExcludeUnless' => 'exclude_unless',
    ];

    /** Framework rule objects that exclude the field when other fields are sent or missing. */
    private const EXCLUDE_FIELD_OBJECTS = [
        'Illuminate\\Validation\\Rules\\ExcludeWith' => 'exclude_with',
        'Illuminate\\Validation\\Rules\\ExcludeWithout' => 'exclude_without',
    ];

    /**
     * Framework rule objects a `Rule::` factory constructs, by that factory: `new Enum(X::class)` is the
     * object `Rule::enum(X::class)` returns, with the same arguments, so it reads as the same rule.
     */
    private const FACTORY_OBJECTS = [
        'Illuminate\\Validation\\Rules\\Enum' => 'enum',
        'Illuminate\\Validation\\Rules\\In' => 'in',
        'Illuminate\\Validation\\Rules\\NotIn' => 'notIn',
        'Illuminate\\Validation\\Rules\\ArrayRule' => 'array',
        'Illuminate\\Validation\\Rules\\Exists' => 'exists',
        'Illuminate\\Validation\\Rules\\Unique' => 'unique',
    ];

    private readonly DependencyFileSet $dependencyFiles;

    private bool $widened = false;

    private RuleLosses $losses;

    public function __construct(
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
    ) {
        $this->dependencyFiles = new DependencyFileSet;
        $this->losses = new RuleLosses;
    }

    /**
     * @return list<ValidationRule>
     */
    public function fold(ConstValue $value): array
    {
        $this->widened = false;
        $this->losses = new RuleLosses;

        if ($value->isScalar() && is_string($value->scalar)) {
            return RuleParsing::tokens($value->scalar);
        }

        $out = [];
        foreach ($value->isArray() ? $value->items : [$value] as $item) {
            $out = [...$out, ...$this->item($item)];
        }

        return $this->losses->opaque() === null ? $out : [];
    }

    /**
     * Files whose contents shaped the fold — the rule classes read for their `#[RuleSchema]`, and any
     * enum whose backing values were quoted into a rule.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->dependencyFiles->all();
    }

    /**
     * The rules the last {@see fold()} saw and left out, each of which can only refuse values: the field's
     * other rules are documented, wider than the endpoint and still true.
     *
     * @return list<string>
     */
    public function unread(): array
    {
        return $this->losses->unread();
    }

    /**
     * The rule that made the last {@see fold()} give up the field — one whose content is unknown, which
     * could make it optional, nullable or excluded — or null when there was none.
     */
    public function opaque(): ?string
    {
        return $this->losses->opaque();
    }

    /**
     * Whether the last {@see fold()} could SEE a constraint it could not read — a value that comes from a
     * call, a variable or a spread — and widened the field rather than publishing a partial one.
     * Its rules are still true; they just say less than the code does, which is a thing to report.
     */
    public function widened(): bool
    {
        return $this->widened;
    }

    /**
     * One entry of a rule list; records what it could not read.
     *
     * @phpstan-impure
     *
     * @return list<ValidationRule>
     */
    private function item(ConstValue $item): array
    {
        return match (true) {
            $item->isScalar() => is_string($item->scalar) ? [RuleParsing::token($item->scalar)] : [],
            $item->isDescriptor() => $this->descriptorItem($item),
            $item->isInstance() => $this->instance($item),
            // A spread, a value from a call or a variable, a nested array: any of them could be `nullable`
            // or `sometimes`, so nothing written beside it is true on its own.
            default => $this->leaveOut(self::label($item), false),
        };
    }

    /**
     * A `new X(...)` rule object documents as whatever its class declares, and a framework object as the
     * `Rule::` factory that builds it, chained calls included. An unannotated class contributes nothing
     * and is named for the caller ({@see UnreadRule}).
     *
     * @return list<ValidationRule>
     */
    private function instance(ConstValue $value): array
    {
        $class = ltrim((string) $value->class, '\\');
        if (isset(self::EXCLUDE_OBJECTS[$class])) {
            $rule = self::exclude(self::EXCLUDE_OBJECTS[$class], $value->args[0] ?? null);

            return $rule === null ? [] : [$rule];
        }

        if (isset(self::EXCLUDE_FIELD_OBJECTS[$class])) {
            return [self::excludeByFields(self::EXCLUDE_FIELD_OBJECTS[$class], $value->args)];
        }

        if (isset(self::FACTORY_OBJECTS[$class])) {
            $descriptor = ConstValue::descriptor(self::FACTORY_OBJECTS[$class], $value->args);
            foreach ($value->chain as $call) {
                $descriptor = $descriptor->withChainedCall($call['method'], $call['args']);
            }

            return $this->descriptorItem($descriptor);
        }

        $facts = $this->customRules->read($class);

        $this->dependencyFiles->add($facts->file);

        if ($facts->annotated) {
            return $facts->rules;
        }

        $unread = UnreadRule::classify($class);

        return $this->leaveOut($unread['label'], $unread['narrows']);
    }

    /**
     * A factory call in the rules list, or a framework object read as one. A `Rule::` static that returns
     * a rule string reads as that string, so `Rule::nullable()` is `'nullable'`. A descriptor that does not
     * read is left out like any other unread rule.
     *
     * @return list<ValidationRule>
     */
    private function descriptorItem(ConstValue $descriptor): array
    {
        $rule = $this->descriptor($descriptor);

        if ($rule !== false) {
            // One that reads to nothing has either said why ({@see $widened}) or excludes nothing.
            return $rule === null ? [] : [$rule];
        }

        $string = $descriptor->chain === [] ? RuleFactories::ruleString((string) $descriptor->factory, $descriptor->args) : null;

        return $string !== null
            ? RuleParsing::tokens($string)
            : $this->leaveOut(self::label($descriptor), UnreadRule::factoryNarrows((string) $descriptor->factory));
    }

    /**
     * Records a rule the fold saw and could not read, by what leaving it out costs, and contributes no rule.
     *
     * @return list<ValidationRule>
     */
    private function leaveOut(string $label, bool $narrows): array
    {
        $this->losses->add($label, $narrows);

        return [];
    }

    /** How a diagnostic names a rule that is no object: the call as written, without its arguments. */
    private static function label(ConstValue $rule): string
    {
        return match (true) {
            $rule->isDescriptor() => self::shortFactory((string) $rule->factory).'(…)',
            $rule->isSpread() => 'a spread',
            $rule->isArray() => 'a nested array',
            default => 'a value this build cannot read',
        };
    }

    /** `Illuminate\\Validation\\Rule::when` → `Rule::when`. */
    private static function shortFactory(string $factory): string
    {
        $separator = strrpos($factory, '::');

        return $separator === false ? Fqcn::short($factory) : Fqcn::short(substr($factory, 0, $separator)).substr($factory, $separator);
    }

    private static function method(ConstValue $descriptor): string
    {
        $factory = strtolower($descriptor->factory ?? '');

        return str_contains($factory, '::') ? substr($factory, strrpos($factory, '::') + 2) : $factory;
    }

    /** The rule a `Rule::` factory this reads builds, null where it builds none, or false where this reads no such factory. */
    private function descriptor(ConstValue $descriptor): ValidationRule|false|null
    {
        $method = self::method($descriptor);

        // A choice descriptor whose values didn't fold is worth LESS than nothing: it would win the merge
        // and then contribute no keyword, so it stays unrecovered for the caller to diagnose.
        // `exists`/`unique` legitimately carry no values.
        $choices = $method === 'in' || $method === 'notin' ? $this->scalarValues($descriptor->args) : [];

        return match (true) {
            $method === 'enum' => $this->enum($descriptor),
            $method === 'in' => $choices === null || $choices === [] ? null : ValidationRule::of('in', $choices, self::caseEnum($descriptor->args)),
            $method === 'notin' => $choices === null || $choices === [] ? null : ValidationRule::of('not_in', $choices),
            // The keys `array:a,b` names only refuse other keys, so an unread one leaves plain `array`.
            $method === 'array' => ValidationRule::of('array', $this->values($descriptor->args) ?? []),
            $method === 'exists' => ValidationRule::of('exists'),
            $method === 'unique' => ValidationRule::of('unique'),
            $method === 'excludeif' => self::exclude('exclude_if', $descriptor->args[0] ?? null),
            $method === 'excludeunless' => self::exclude('exclude_unless', $descriptor->args[0] ?? null),
            $method === 'exclude' => ValidationRule::of('exclude'),
            $method === 'excludewith' => self::excludeByFields('exclude_with', $descriptor->args),
            $method === 'excludewithout' => self::excludeByFields('exclude_without', $descriptor->args),
            default => false,
        };
    }

    /**
     * `Rule::excludeIf(…)`/`Rule::excludeUnless(…)` and their `new` forms, which stop Laravel running the
     * field's later rules when their condition says so ({@see RuleSetNormalizer}). A literal bool settles
     * it now: bare `exclude`, or no rule at all. Any other condition is a callback nothing here can run,
     * so the rule is kept with no parameters — an exclude rule whose condition is unknown.
     */
    private static function exclude(string $name, ?ConstValue $condition): ?ValidationRule
    {
        if ($condition === null || ! $condition->isScalar() || ! is_bool($condition->scalar)) {
            return ValidationRule::of($name);
        }

        return $condition->scalar === ($name === 'exclude_if') ? ValidationRule::of('exclude') : null;
    }

    /**
     * `Rule::excludeWith(…)`/`Rule::excludeWithout(…)` and their `new` forms, named by the other fields
     * whose presence decides it: one name, a list of them, or the names as separate arguments. A name
     * nothing here can read leaves the rule with no parameters, an exclude whose condition is unknown.
     *
     * @param  list<ConstValue>  $args
     */
    private static function excludeByFields(string $name, array $args): ValidationRule
    {
        $source = count($args) === 1 && $args[0]->isArray() ? $args[0]->items : $args;

        $fields = [];
        foreach ($source as $arg) {
            if (! $arg->isScalar() || ! is_string($arg->scalar)) {
                return ValidationRule::of($name);
            }

            $fields[] = $arg->scalar;
        }

        return ValidationRule::of($name, $fields);
    }

    private function enum(ConstValue $descriptor): ?ValidationRule
    {
        $class = $descriptor->args[0] ?? null;
        $fqcn = $class !== null && $class->isScalar() && is_string($class->scalar) ? ltrim($class->scalar, '\\') : '';
        if ($fqcn === '') {
            // The enum class comes from somewhere nothing names, so no case list can be published.
            $this->widened = true;

            return null;
        }

        // The backing VALUES go into the rule, so adding a case rewrites it while the request class the
        // rule was read from hasn't moved.
        $this->dependencyFiles->add(EnumReflection::file($fqcn));

        $values = array_map(strval(...), EnumReflection::values($fqcn));

        if ($descriptor->chain !== []) {
            $values = $this->narrowEnum($fqcn, $values, $descriptor->chain);
        }

        return $values === [] ? null : ValidationRule::of('enum', $values, $fqcn);
    }

    /**
     * Applies a `Rule::enum(…)->only([…])/->except([…])` chain to the recovered backing values. Laravel
     * keeps or drops a case by comparing it strictly with the cases listed, so a selector selects only a
     * case of this enum, matched by name — {@see EnumReflection::names()} runs parallel to
     * {@see EnumReflection::values()} — and order is preserved. An unknown chain method is ignored and the
     * full case list stands.
     *
     * @param  list<string>  $values
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     * @return list<string>
     */
    private function narrowEnum(string $fqcn, array $values, array $chain): array
    {
        $names = EnumReflection::names($fqcn);
        $pairs = [];
        foreach ($names as $index => $name) {
            $pairs[] = ['name' => $name, 'value' => $values[$index] ?? $name];
        }

        foreach ($chain as $call) {
            // Selectors that would not read are the one place narrowing must NOT happen: a half-read
            // `only([...])` would drop cases the endpoint accepts. The full list stands instead.
            // An empty list narrows nothing in Laravel either.
            $selected = self::entries($call['args']) === [] ? null : $this->selection($call['args'], $fqcn);
            if ($selected === null) {
                continue;
            }

            $pairs = match ($call['method']) {
                'only' => array_values(array_filter($pairs, static fn (array $p): bool => in_array($p['name'], $selected, true))),
                'except' => array_values(array_filter($pairs, static fn (array $p): bool => ! in_array($p['name'], $selected, true))),
                default => $pairs,
            };
        }

        return array_map(static fn (array $p): string => (string) $p['value'], $pairs);
    }

    /**
     * The values an argument list states — a `Rule::in(...)` choice list, an `->only([...])` selection —
     * whether written as one array argument or as several. Null unless EVERY entry named a value: an entry
     * that names one this build cannot read is a value MISSING from the list, and a published enum missing
     * a legal value makes a generated client REJECT what the API accepts. There is no truthful partial
     * answer, so the caller widens to no constraint and says so.
     *
     * The reading is per entry rather than per shape on purpose. A spread or a named argument
     * ({@see ArgumentSlots}) is only the loudest way a value goes unread — an argument the fold gave up on
     * ({@see ConstValue::unknown()}), a nested array, another descriptor, a bare null all say the same
     * thing, and a check that recognised fewer shapes than the fold produces would let the truncation back
     * in through the quieter ones.
     *
     * An argument list with no entries at all is a different thing: `Rule::in()` states nothing, so there
     * is nothing to have missed, and the empty list is what the caller reads as "no constraint recovered".
     *
     * @param  list<ConstValue>  $args
     * @return list<string>|null
     */
    private function scalarValues(array $args): ?array
    {
        $values = $this->values($args);
        if ($values === null) {
            $this->widened = true;
        }

        return $values;
    }

    /**
     * The values an argument list states, or null unless every entry named one ({@see scalarValues()}). An
     * enum case is the value Laravel casts it to ({@see EnumCaseValue}), so `Rule::in([Status::Draft])`
     * states `draft` and never `Draft`.
     *
     * @param  list<ConstValue>  $args
     * @return list<string>|null
     */
    private function values(array $args): ?array
    {
        $out = [];
        foreach (self::entries($args) as $arg) {
            $value = $arg->isCase() ? EnumCaseValue::of($arg) : ($arg->isScalar() ? $arg->scalar : null);
            if ($value === null) {
                return null;
            }

            $this->dependencyFiles->add(EnumCaseValue::file($arg));
            $out[] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $out;
    }

    /**
     * The case names of `$fqcn` an `->only()`/`->except()` argument list selects, or null when an entry
     * would not read. A value that is not a case of this enum — a string, another enum's case — is never
     * strictly equal to one, so it selects nothing.
     *
     * @param  list<ConstValue>  $args
     * @return list<string>|null
     */
    private function selection(array $args, string $fqcn): ?array
    {
        $names = [];
        foreach (self::entries($args) as $arg) {
            $name = $arg->caseName();
            if ($name === null && (! $arg->isScalar() || $arg->scalar === null)) {
                $this->widened = true;

                return null;
            }

            if ($name !== null && ltrim((string) $arg->class, '\\') === $fqcn) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The enum every entry of a choice list is a case of, or null where any entry is something else. A rule
     * listing one enum's cases is a choice from that enum, so it is named, described and referenced as one.
     *
     * @param  list<ConstValue>  $args
     */
    private static function caseEnum(array $args): ?string
    {
        $classes = [];
        foreach (self::entries($args) as $arg) {
            if (! $arg->isCase()) {
                return null;
            }

            $classes[ltrim((string) $arg->class, '\\')] = true;
        }

        return count($classes) === 1 ? (string) array_key_first($classes) : null;
    }

    /**
     * An argument list's entries: `Rule::in(['a', 'b'])` folds arg 0 to an array, `Rule::in('a', 'b')` to a
     * list of args.
     *
     * @param  list<ConstValue>  $args
     * @return list<ConstValue>
     */
    private static function entries(array $args): array
    {
        return count($args) === 1 && $args[0]->isArray() ? $args[0]->items : $args;
    }
}
