<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `file`/`image` → a binary string schema, and the whole request switches to `multipart/form-data` since a
 * file field can't be JSON. `image` also notes itself in the description.
 *
 * `mimes`/`mimetypes`/`extensions`/`dimensions` imply an upload too, so they flip multipart, but add
 * nothing to the schema — the binary type comes from the accompanying `file`/`image` rule these almost
 * always carry. `dimensions` becomes a description note; OpenAPI has no pixel-dimension keyword.
 */
final class FileRuleTransformer implements RuleTransformer
{
    private const MULTIPART_ONLY = ['mimes', 'mimetypes', 'extensions'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['file', 'image', 'dimensions', ...self::MULTIPART_ONLY];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $field->markMultipart();

        if ($rule->name === 'dimensions') {
            if ($rule->parameters !== []) {
                $note = 'Image dimensions: '.implode(', ', $rule->parameters).'.';
                $existing = $field->get('description');
                $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
            }

            return;
        }

        if (in_array($rule->name, self::MULTIPART_ONLY, true)) {
            return;
        }

        $field->setType('string');
        $field->set('format', 'binary');

        if ($rule->name === 'image' && ! $field->has('description')) {
            $field->set('description', 'An image file.');
        }
    }
}
