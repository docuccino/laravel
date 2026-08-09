<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Attributes\RuleSchema;
use ReflectionClass;
use Throwable;

/**
 * Reads a custom rule class's `#[RuleSchema]` — the class-level contract that makes a rule OBJECT
 * documentable — into rules for the shared chain, the same way a Spatie custom filter class carries a
 * `#[QueryParameter]`. The attribute is the whole contract: the class needn't implement Laravel's
 * `ValidationRule` (a vendor rule works the moment its author adds the attribute), and constructor
 * arguments are ignored — the shape is declared, not computed.
 *
 * Reflection/attribute failures degrade to no facts; nothing is instantiated.
 */
final class CustomRuleReader
{
    public function read(string $fqcn): CustomRuleFacts
    {
        if (! class_exists($fqcn)) {
            return new CustomRuleFacts;
        }

        try {
            $reflection = new ReflectionClass($fqcn);
            $file = $reflection->getFileName();
            $attributes = $reflection->getAttributes(RuleSchema::class);

            return new CustomRuleFacts(
                rules: $attributes === [] ? [] : RuleSchemaRules::of($attributes[0]->newInstance()),
                file: $file === false ? null : $file,
            );
        } catch (Throwable) {
            return new CustomRuleFacts;
        }
    }
}
