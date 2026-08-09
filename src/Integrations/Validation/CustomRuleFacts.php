<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * What a custom rule class contributes: the rules its `#[RuleSchema]` maps to (empty when it carries
 * none) and its declaring file. The file comes back either way — adding the attribute later has to
 * invalidate the fragment too.
 */
final readonly class CustomRuleFacts
{
    /**
     * @param  list<ValidationRule>  $rules
     */
    public function __construct(
        public array $rules = [],
        public ?string $file = null,
    ) {}
}
