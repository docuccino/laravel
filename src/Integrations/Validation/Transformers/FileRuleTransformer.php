<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `file`/`image` → a binary string schema and a request-wide switch to `multipart/form-data` (a
 * file field can't be JSON). `image` additionally notes the constraint in the description.
 *
 * `mimes`/`mimetypes`/`extensions` also imply an uploaded file, so they flip the request to multipart
 * too — but they contribute nothing else to the field schema (the binary type comes from an
 * accompanying `file`/`image` rule, which these almost always carry). Handling them here also stops
 * them raising a spurious unhandled-rule diagnostic.
 *
 * `dimensions` (image width/height constraints) likewise implies an uploaded image, so it flips
 * multipart; OpenAPI has no keyword for pixel dimensions, so the constraint list becomes a
 * description note rather than a wrong schema claim (the binary type comes from the accompanying
 * `image` rule).
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
