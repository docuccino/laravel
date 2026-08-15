<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;

/**
 * Folds one field's statically-recovered rules — from an inline `validate([...])` or
 * `Validator::make(...)` — into {@see ValidationRule}s: pipe strings, array-of-rule forms, `Rule::*`
 * factory descriptors, and `new` rule objects whose class carries a `#[RuleSchema]`. Descriptors are why
 * folding happens at the AST level: `Rule::enum(Status::class)` has to be caught as a call, before
 * PHPStan collapses it to a bare object.
 *
 * The rule classes it read come back as {@see dependencyFiles()} for the caller to record — editing an
 * annotated rule has to re-document every field using it. One folder per recovery, never shared.
 */
final class ConstValueToRules
{
    /**
     * @var list<string>
     */
    private array $dependencyFiles = [];

    public function __construct(
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
    ) {}

    /**
     * @return list<ValidationRule>
     */
    public function fold(ConstValue $value): array
    {
        if ($value->isScalar() && is_string($value->scalar)) {
            return RuleParsing::tokens($value->scalar);
        }

        if ($value->isDescriptor()) {
            $rule = $this->descriptor($value);

            return $rule === null ? [] : [$rule];
        }

        if ($value->isInstance()) {
            return $this->instance($value);
        }

        if ($value->isArray()) {
            $out = [];
            foreach ($value->items as $item) {
                if ($item->isScalar() && is_string($item->scalar)) {
                    $out[] = RuleParsing::token($item->scalar);
                } elseif ($item->isDescriptor()) {
                    $rule = $this->descriptor($item);
                    if ($rule !== null) {
                        $out[] = $rule;
                    }
                } elseif ($item->isInstance()) {
                    $out = [...$out, ...$this->instance($item)];
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Files whose contents shaped the fold — the rule classes read for their `#[RuleSchema]`, and any
     * enum whose backing values were quoted into a rule.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->dependencyFiles;
    }

    /** Remember a file the fold read, if there was one. */
    private function remember(?string $file): void
    {
        if ($file !== null && ! in_array($file, $this->dependencyFiles, true)) {
            $this->dependencyFiles[] = $file;
        }
    }

    /**
     * A `new X(...)` rule object documents as whatever its class declares. An unannotated class
     * contributes nothing, leaving the field unrecoverable and diagnosed.
     *
     * @return list<ValidationRule>
     */
    private function instance(ConstValue $value): array
    {
        $facts = $this->customRules->read(ltrim((string) $value->class, '\\'));

        $this->remember($facts->file);

        return $facts->rules;
    }

    private function descriptor(ConstValue $descriptor): ?ValidationRule
    {
        $factory = strtolower($descriptor->factory ?? '');
        $method = str_contains($factory, '::') ? substr($factory, strrpos($factory, '::') + 2) : $factory;

        // A choice descriptor whose values didn't fold is worth LESS than nothing: it would win the merge
        // and then contribute no keyword, so it stays unrecovered for the caller to diagnose.
        // `exists`/`unique` legitimately carry no values.
        $choices = $method === 'in' ? $this->scalarArgs($descriptor) : [];

        return match (true) {
            $method === 'enum' => $this->enum($descriptor),
            $method === 'in' => $choices === [] ? null : ValidationRule::of('in', $choices),
            $method === 'exists' => ValidationRule::of('exists'),
            $method === 'unique' => ValidationRule::of('unique'),
            default => null,
        };
    }

    private function enum(ConstValue $descriptor): ?ValidationRule
    {
        $class = $descriptor->args[0] ?? null;
        $fqcn = $class !== null && $class->isScalar() && is_string($class->scalar) ? ltrim($class->scalar, '\\') : '';

        // The backing VALUES go into the rule, so adding a case rewrites it while the request class the
        // rule was read from hasn't moved.
        $this->remember($fqcn === '' ? null : EnumReflection::file($fqcn));

        $values = $fqcn === '' ? [] : array_map(strval(...), EnumReflection::values($fqcn));

        if ($fqcn !== '' && $descriptor->chain !== []) {
            $values = $this->narrowEnum($fqcn, $values, $descriptor->chain);
        }

        return $values === [] ? null : ValidationRule::of('enum', $values, $fqcn);
    }

    /**
     * Applies a `Rule::enum(…)->only([…])/->except([…])` chain to the recovered backing values. The chained
     * args fold to case *names* (that's what the engine folds an enum-case constant to), so pair each name
     * with its backing value — {@see EnumReflection::names()} runs parallel to
     * {@see EnumReflection::values()} — and keep/drop by name, preserving order. An unknown chain method is
     * ignored and the full case list stands.
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
            $selected = $this->chainSelectors($call['args']);
            if ($selected === []) {
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
     * The case names a chain call selects, whether written as one array arg or spread.
     *
     * @param  list<ConstValue>  $args
     * @return list<string>
     */
    private function chainSelectors(array $args): array
    {
        $source = count($args) === 1 && $args[0]->isArray() ? $args[0]->items : $args;

        $out = [];
        foreach ($source as $arg) {
            if ($arg->isScalar() && is_string($arg->scalar)) {
                $out[] = $arg->scalar;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function scalarArgs(ConstValue $descriptor): array
    {
        // `Rule::in(['a', 'b'])` folds arg 0 to an array; `Rule::in('a', 'b')` to a list of args.
        $source = count($descriptor->args) === 1 && $descriptor->args[0]->isArray()
            ? $descriptor->args[0]->items
            : $descriptor->args;

        $out = [];
        foreach ($source as $arg) {
            if ($arg->isScalar() && $arg->scalar !== null) {
                $out[] = is_bool($arg->scalar) ? ($arg->scalar ? '1' : '0') : (string) $arg->scalar;
            }
        }

        return $out;
    }
}
