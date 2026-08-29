<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Extensions\BuiltIn\AttributeExamplesExtension;
use Docuccino\Core\Extensions\BuiltIn\AttributeOverridesExtension;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\UnusableBodyDeclarations;
use Docuccino\Core\Lint\ExampleSchemaLint;
use Docuccino\Core\Lint\MissingDescriptionLint;
use Docuccino\Core\Lint\OperationIdStyleLint;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\UndocumentedTagLint;
use Docuccino\Core\Lint\UnpinnedRedirectLint;
use Docuccino\Core\Lint\VacuousUnionLint;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;
use Docuccino\Laravel\Extensions\AttributeSecurityExtension;
use Docuccino\Laravel\Extensions\DeclaredErrorComponentsExtension;
use Docuccino\Laravel\Extensions\ErrorResponsesExtension;
use Docuccino\Laravel\Extensions\FrameworkResponseTypeToSchema;
use Docuccino\Laravel\Extensions\IgnoredParametersExtension;
use Docuccino\Laravel\Extensions\ImplicitResponsesExtension;
use Docuccino\Laravel\Extensions\InferredResponsesExtension;
use Docuccino\Laravel\Extensions\PathParametersExtension;
use Docuccino\Laravel\Extensions\RecordedExamplesExtension;
use Docuccino\Laravel\Extensions\RouteServersExtension;
use Docuccino\Laravel\Extensions\SecurityExtension;
use Docuccino\Laravel\Extensions\UnmatchedIgnoredResponsesExtension;
use Docuccino\Laravel\Extensions\ViewMediaType;
use Docuccino\Laravel\Extensions\ViewTypeToSchema;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsIntegration;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerIntegration;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsIntegration;
use Docuccino\Laravel\Integrations\Support\AuthConfigDigestContributor;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;

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
            // Auth config is the framework's, not any auth package's, so it keys the cache whether or
            // not one of them is installed.
            AuthConfigDigestContributor::class,
            AttributeOverridesExtension::class,
            // Reads a committed file of responses a test suite recorded; nothing is executed here.
            RecordedExamplesExtension::class,
            // Finalize: every parameter every producer contributes now exists, so the subtractive
            // #[IgnoreParam] can drop one without a later producer writing it back.
            IgnoredParametersExtension::class,
            // Finalize: a status only becomes a $ref once a mapper resolves, so this is the first point
            // at which a declared error-component name is known to have reached nothing.
            DeclaredErrorComponentsExtension::class,
            // Finalize: every response, parameter and request body a #[Example] could name now exists.
            AttributeExamplesExtension::class,
            // Finalize, LAST: #[IgnoreResponse] is consulted per producer, so the end of the route's
            // build is the first place that can tell a declaration was never asked about at all.
            UnmatchedIgnoredResponsesExtension::class,
            RouteServersExtension::class,
            // A rendered view answers text/html; the built-in resolver sits in the same gated chain the
            // integrations' media-type matchers do.
            ViewMediaType::class,
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
            // Ahead of the core mappers: a framework response object and a rendered view are transport,
            // not bodies, so neither may reach the framework-agnostic class mapper and be reflected into
            // a component.
            FrameworkResponseTypeToSchema::class,
            ViewTypeToSchema::class,
            ...DefaultTypeMappers::all(),
            // Collapses an error body repeated across operations into one shared component + $refs.
            // The only document transformer that moves a byte, so everything reading the finished
            // document comes after it.
            SharedErrorResponses::class,
            // Derives the API version this document IS, after the last producer of bytes and before the
            // lints, so they read what will be emitted. A document declaring no `api_version` is not a
            // version and this moves nothing.
            ApiVersionTransformer::class,
            // The document lints. All diagnostics-only, and all pinned to Priorities::LAST so they read
            // what will be emitted — this list's order is not what settles that, the attribute is.
            SensitiveFieldLint::class,
            MissingDescriptionLint::class,
            OperationIdStyleLint::class,
            UndocumentedTagLint::class,
            VacuousUnionLint::class,
            ExampleSchemaLint::class,
            UnpinnedRedirectLint::class,
            // Diagnostics-only too: what is wrong with the committed recordings, said once per document.
            RecordedExampleAudit::class,
            // Both a note collector and a document transformer, so it is ONE registration: a
            // `#[BodyParameter]` on a request type is unusable at a read verb and load-bearing at a
            // write one, and only the whole route set can tell which a type has.
            UnusableBodyDeclarations::class,
        ];
    }
}
