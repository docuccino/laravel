<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Base-type rules → a JSON Schema `type` (plus `format` for the string-shaped ones): `string`,
 * `integer`, `numeric`, `boolean`, `array`, `email`, `uuid`, `date`. Runs before the constraint
 * transformers so type-aware rules (`min`/`max`) can read the resolved type.
 *
 * The format is a reading of intent — `date` is one word for everything non-relative `strtotime` parses —
 * so it is claimed only where nothing better has spoken ({@see ValidationField::mayClaim()}): a rule that
 * stated the real wire pattern, or withdrew a format nothing describes, has spoken.
 */
final class TypeRuleTransformer implements RuleTransformer
{
    /**
     * @var array<string, array{type: string, format?: string}>
     */
    private const TYPES = [
        'string' => ['type' => 'string'],
        'integer' => ['type' => 'integer'],
        'int' => ['type' => 'integer'],
        'numeric' => ['type' => 'number'],
        'boolean' => ['type' => 'boolean'],
        'bool' => ['type' => 'boolean'],
        'array' => ['type' => 'array'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'uuid' => ['type' => 'string', 'format' => 'uuid'],
        'ulid' => ['type' => 'string', 'format' => 'ulid'],
        'url' => ['type' => 'string', 'format' => 'uri'],
        'ip' => ['type' => 'string', 'format' => 'ip'],
        'date' => ['type' => 'string', 'format' => 'date'],
    ];

    public function supports(ValidationRule $rule): bool
    {
        return isset(self::TYPES[$rule->name]);
    }

    public function handledRuleNames(): array
    {
        return array_keys(self::TYPES);
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $mapping = self::TYPES[$rule->name];

        $field->setType($mapping['type']);
        if (isset($mapping['format']) && $field->mayClaim('format')) {
            $field->set('format', $mapping['format']);
        }
    }
}
