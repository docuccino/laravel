<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Provenance\Source;

/**
 * The one report for an `#[ErrorComponent]` that named something no component key can carry.
 *
 * Two producers read that attribute — a built-in extension on the exception class, the inferred-handler
 * integration on a render method — and an extension may not import an integration, so the statement
 * lives here rather than existing twice. Only the declaration to go and fix differs between them: the
 * fact, the consequence and the remedy are one, and a second copy is free to drift from the first.
 */
final class ErrorComponentDiagnostic
{
    /**
     * `%s` is the declaring symbol, then the name it declared. Public so the one-owner pin can read the
     * sentence it guards out of the class that owns it.
     */
    public const string ILLEGAL_NAME_MESSAGE = '%s declares #[ErrorComponent("%s")], which is not a name an OpenAPI component key can carry, so the attribute names nothing and the response keeps the name it would have had.';

    /**
     * A warning, not an error: `claimComponentName()` drops the name at the write and the response keeps
     * the one it would have had, so the document is true and the author has a line of code that does
     * nothing. $source is where the declaration was written, never the throw site.
     */
    public static function illegalName(
        string $declaredBy,
        string $name,
        ?Source $source,
        ?string $routeSignature,
    ): Diagnostic {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.error-component-invalid',
            message: sprintf(self::ILLEGAL_NAME_MESSAGE, $declaredBy, $name),
            source: $source,
            routeSignature: $routeSignature,
            help: ComponentNames::LEGAL_NAME_HELP,
        );
    }
}
