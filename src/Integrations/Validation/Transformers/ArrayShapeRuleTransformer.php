<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `list` → `type: array`, a Laravel `list` being a sequentially-keyed array.
 *
 * `distinct` on `tags.*` → `uniqueItems: true` on `tags`: Laravel compares the value against every other
 * value its own pattern matches, so it speaks about the list, not the item. Published only where that is
 * the same claim — {@see uniqueItems()} says when.
 */
final class ArrayShapeRuleTransformer implements RuleTransformer
{
    /** Item types whose values Laravel's `distinct` compares, every one of them a JSON scalar. */
    private const SCALARS = ['string', 'integer', 'number', 'boolean'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['list', 'distinct'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if ($rule->name === 'list') {
            $field->setType('array');

            return;
        }

        $list = $field->container();
        if ($list !== null && self::uniqueItems($rule, $field, $list)) {
            $list->set('uniqueItems', true);
        }
    }

    /**
     * Whether `uniqueItems` on the list is true of what `distinct` on its items refuses. It refuses only
     * JSON-equal items, which Laravel's loose comparison and its `ignore_case` refuse too — and more.
     *
     * Not on a key path (`tags`, `lines.*.sku`): the first matches only itself, so Laravel never refuses
     * it, and the second compares values across objects, which no keyword states. Not where an item may
     * be an array or object, which Laravel never compares, nor null, which it skips on a nullable item,
     * nor a number under `strict`, which takes `1` beside `1.0` — each a repeat `uniqueItems` refuses and
     * the server takes. And not on an object's values, which have no keyword either.
     */
    private static function uniqueItems(ValidationRule $rule, ValidationField $item, ValidationField $list): bool
    {
        $types = $item->types();
        $container = $list->types();
        $strict = in_array('strict', $rule->parameters, true) && ! in_array('ignore_case', $rule->parameters, true);

        return $types !== []
            && array_diff($types, self::SCALARS) === []
            && ! ($strict && array_intersect($types, ['integer', 'number']) !== [])
            && ! $item->isNullable()
            && ! $list->has('additionalProperties')
            && ($container === [] || in_array('array', $container, true));
    }
}
