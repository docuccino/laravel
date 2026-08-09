<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Extensions\BuiltIn\AttributeOverridesExtension;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;
use Docuccino\Laravel\Extensions\AttributeSecurityExtension;
use Docuccino\Laravel\Extensions\ErrorResponsesExtension;
use Docuccino\Laravel\Extensions\ImplicitResponsesExtension;
use Docuccino\Laravel\Extensions\InferredResponsesExtension;
use Docuccino\Laravel\Extensions\PathParametersExtension;
use Docuccino\Laravel\Extensions\SecurityExtension;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsIntegration;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerIntegration;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsIntegration;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Routing\LaravelRouteResolver;

/**
 * The built-in extension set (dogfooding the public API — arch-enforceable: everything here
 * implements only the core contracts). Class-strings are container-resolved; the core type
 * mappers are stateless instances. Resolved through the same {@see ExtensionRegistry} path as
 * config and programmatic registrations.
 *
 * @internal
 */
final class DefaultExtensions
{
    /**
     * The built-in set gated for one document. Each integration's extensions are contributed only when
     * its package is installed AND the document enables it ({@see IntegrationToggles}) — the per-document
     * enable/disable seam. The gate preserves the built-in ordering: each spread sits in the same
     * position its unconditional predecessor did, so a document that enables everything (the common
     * case) resolves to byte-identical output.
     *
     * @return list<class-string|object>
     */
    public static function all(DocumentConfig $document): array
    {
        $toggles = IntegrationToggles::descriptors();
        $enabled = static fn (string $key): array => $toggles[$key]->installed() && $toggles[$key]->enabledFor($document)
            ? $toggles[$key]->extensions()
            : [];

        return [
            LaravelRouteResolver::class,
            PathParametersExtension::class,
            AttributeParametersExtension::class,
            AttributeRequestBodyExtension::class,
            InferredResponsesExtension::class,
            AttributeResponsesExtension::class,
            ErrorResponsesExtension::class,
            ImplicitResponsesExtension::class,
            SecurityExtension::class,
            AttributeSecurityExtension::class,
            AttributeOverridesExtension::class,
            // Error-response chain (design §6, first supports() wins). Ordered by priority:
            // inferred handler = the app's REAL error shapes (FIRST), Problem Details preset (EARLY,
            // self-gated on error_responses => 'problem-details'), framework-default JSON shapes
            // (LATE, always on), terminal generic fallback (LAST).
            ...InferredHandlerIntegration::extensions(),
            ...ProblemDetailsIntegration::extensions(),
            ...FrameworkErrorsIntegration::extensions(),
            DefaultExceptionToResponse::class,
            // FormRequest / inline validate() request documentation (design §Phase 4). Consumes only
            // public contracts (dogfooding); the rule vocabulary registers through the same chain.
            ValidationRequestExtension::class,
            ...ValidationIntegration::transformers(),
            // Reflection-rich enum schemas (backing values, #[CaseDescription] → x-enumDescriptions);
            // ordered ahead of the core case-names-only mapper.
            EnumSchema::class,
            // Per-document, per-integration gate (installed AND enabled). Framework built-ins
            // (api_resources/eloquent/rate_limit) are always installed; the package-backed integrations
            // add their class_exists probe. Default-on except permission (opt-in — see IntegrationToggles).
            ...$enabled('api_resources'),
            ...$enabled('timacdonald_json_api'),
            ...$enabled('eloquent'),
            ...$enabled('rate_limit'),
            ...$enabled('spatie_data'),
            ...$enabled('query_builder'),
            ...$enabled('json_api_paginate'),
            ...$enabled('laravel_actions'),
            ...$enabled('sanctum'),
            ...$enabled('passport'),
            ...$enabled('permission'),
            ...DefaultTypeMappers::all(),
            // Data-leakage lint (always-on core DocumentTransformer): warns on schema properties whose
            // names look sensitive (password/token/secret/…); diagnostics only, never mutates output.
            // Container-resolved so the provider maps the docuccino.lint.leakage config onto its options.
            SensitiveFieldLint::class,
        ];
    }
}
