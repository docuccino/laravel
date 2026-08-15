<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UploadData;

/**
 * A Data property typed `Illuminate\Http\UploadedFile` (incl. `?UploadedFile` and a list of it) IS a
 * file upload — it must emit a binary schema and flip the request to multipart/form-data whatever the
 * (possibly dynamic, un-foldable) rules() recovered. Recovery runs through the REAL DataClassReflector
 * (real reflection over {@see UploadData}) + the shared Laravel validation chain, so this proves the
 * type-detection half, not just a stubbed shape.
 */
const UPLOAD_FILE = 'Illuminate\\Http\\UploadedFile';

it('types an UploadedFile Data property as a binary multipart field, no file rule required', function (): void {
    $result = buildUploadSchema([
        new PropertyMetadata('file', new ClassT(UPLOAD_FILE)),
    ]);

    expect($result->mediaType)->toBe('multipart/form-data')
        ->and($result->schema['properties']['file'])->toBe(['type' => 'string', 'format' => 'binary'])
        ->and($result->schema['required'] ?? [])->toContain('file');
});

it('keeps a nullable UploadedFile property binary and null-admitting', function (): void {
    $result = buildUploadSchema([
        new PropertyMetadata('avatar', UnionT::of([new ClassT(UPLOAD_FILE), new NullT])),
    ]);

    expect($result->mediaType)->toBe('multipart/form-data')
        ->and($result->schema['properties']['avatar'])->toBe(['type' => ['string', 'null'], 'format' => 'binary'])
        // Nullable ⇒ not required.
        ->and($result->schema['required'] ?? [])->not->toContain('avatar');
});

it('types a list of UploadedFile as a multipart array of binary items', function (): void {
    $result = buildUploadSchema([
        new PropertyMetadata('documents', new ListT(new ClassT(UPLOAD_FILE))),
    ]);

    expect($result->mediaType)->toBe('multipart/form-data')
        ->and($result->schema['properties']['documents'])->toBe([
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'binary'],
        ]);
});

it('documents a mixed json+file body under multipart with scalar fields intact', function (): void {
    $result = buildUploadSchema([
        new PropertyMetadata('file', new ClassT(UPLOAD_FILE)),
        new PropertyMetadata('title', ScalarT::string()),
    ]);

    expect($result->mediaType)->toBe('multipart/form-data')
        ->and($result->schema['properties']['file'])->toBe(['type' => 'string', 'format' => 'binary'])
        ->and($result->schema['properties']['title'])->toBe(['type' => 'string']);
});

/**
 * Build the request schema for a subset of {@see UploadData}'s properties through the real
 * DataValidationRules recovery + the shared normalise/order/convert sequence the extension runs.
 *
 * @param  list<PropertyMetadata>  $properties
 */
function buildUploadSchema(array $properties): ValidationSchema
{
    $metadata = new ClassMetadata(UploadData::class, $properties);
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    $ruleSet = (new DataValidationRules)->build(UploadData::class, $metadata, new NullTypeEngine, null, $context);
    $ordered = (new RuleOrdering)->order((new RuleSetNormalizer)->normalize($ruleSet));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $context);
}
