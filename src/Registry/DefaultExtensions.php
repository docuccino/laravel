<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Extensions\BuiltIn\AttributeOverridesExtension;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
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
 * The built-in extension set, dogfooding the public API: everything here implements only the core
 * contracts. Class-strings are container-resolved, the core type mappers are stateless instances, and
 * the whole set goes through the same {@see ExtensionRegistry} path as config and programmatic
 * registrations.
 *
 * @internal
 */
final class DefaultExtensions
{
    /**
     * The built-in set gated for one document via {@see IntegrationToggles} (installed AND enabled).
     * Each gated spread sits exactly where its unconditional predecessor did, so ordering is preserved
     * and a document that enables everything resolves to byte-identical output.
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
            // Error-response chain, first supports() wins (design §6): the app's real error shapes,
            // then the Problem Details preset (self-gated on error_responses), then framework defaults,
            // then a generic fallback.
            ...InferredHandlerIntegration::extensions(),
            ...ProblemDetailsIntegration::extensions(),
            ...FrameworkErrorsIntegration::extensions(),
            DefaultExceptionToResponse::class,
            // FormRequest / inline validate() request documentation; the rule vocabulary registers
            // through the same chain.
            ValidationRequestExtension::class,
            ...ValidationIntegration::transformers(),
            // Reflection-rich enum schemas (backing values, #[CaseDescription]) — must sit ahead of the
            // core case-names-only mapper.
            EnumSchema::class,
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
            // Data-leakage lint: warns on sensitive-looking property names. Diagnostics only, never
            // mutates output.
            SensitiveFieldLint::class,
            // Collapses an error body repeated across operations into one shared component + $refs.
            SharedErrorResponses::class,
        ];
    }
}
