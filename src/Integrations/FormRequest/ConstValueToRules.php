<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\Support\RuleParsing;

/**
 * Folds a statically-recovered {@see ConstValue} (one field's rules from an inline `validate([...])`
 * or `Validator::make(...)`) into {@see ValidationRule}s. Handles pipe strings, array-of-rule forms,
 * and `Rule::*` factory descriptors — the descriptor variant is why folding happens at the AST level
 * (`Rule::enum(Status::class)` is recovered as a call, before PHPStan collapses it to a bare object).
 */
final class ConstValueToRules
{
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
                }
            }

            return $out;
        }

        return [];
    }

    private function descriptor(ConstValue $descriptor): ?ValidationRule
    {
        $factory = strtolower($descriptor->factory ?? '');
        $method = str_contains($factory, '::') ? substr($factory, strrpos($factory, '::') + 2) : $factory;

        return match ($method) {
            'enum' => $this->enum($descriptor),
            'in' => ValidationRule::of('in', $this->scalarArgs($descriptor)),
            'exists' => ValidationRule::of('exists'),
            'unique' => ValidationRule::of('unique'),
            default => null,
        };
    }

    private function enum(ConstValue $descriptor): ValidationRule
    {
        $class = $descriptor->args[0] ?? null;
        $fqcn = $class !== null && $class->isScalar() && is_string($class->scalar) ? ltrim($class->scalar, '\\') : '';

        $values = $fqcn === '' ? [] : array_map(strval(...), EnumReflection::values($fqcn));

        if ($fqcn !== '' && $descriptor->chain !== []) {
            $values = $this->narrowEnum($fqcn, $values, $descriptor->chain);
        }

        return ValidationRule::of('enum', $values, $fqcn === '' ? null : $fqcn);
    }

    /**
     * Apply a `Rule::enum(...)` fluent chain (`->only([...])` / `->except([...])`) to the recovered
     * backing-value list. The chained args fold to case NAMES (the engine folds an enum-case constant
     * to its case name), so we pair
     * each name with its backing value ({@see EnumReflection::names()} runs parallel to
     * {@see EnumReflection::values()}) and keep/drop by name — order-preserving. Unknown chain methods
     * are ignored (the full case list stands), matching the safe floor elsewhere.
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
     * The case names selected by a fluent chain call — `->only([Status::A, Status::B])` (a single
     * array arg) or `->only(Status::A, Status::B)` (spread), each case folded to its name scalar.
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
        // `Rule::in(['a', 'b'])` folds arg 0 to an array of scalars; `Rule::in('a', 'b')` to a list of
        // scalar args. Flatten either into a plain string list.
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
