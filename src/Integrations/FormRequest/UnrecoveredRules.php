<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\DeclaredFields;

/**
 * The two notes a rules recovery owes for a field it could not fully read, and the stand-down both take:
 * a field a declaration already publishes is written from a layer above the recovery, so what became of
 * it is not the recovery's claim to make ({@see DeclaredFields}).
 *
 * Each recovery composes its own sentence, because they name the field differently — a class's `rules()`
 * names the class, an inline `validate()` has only the route. The ADDRESS is not theirs to compose: the
 * route travels with every note from here, so a reader handed "field x on SomeRequest" is never left to
 * work out which route reached it. Owning it here is what stops the two recoveries answering differently
 * — one of them already did, and a FormRequest note reached the artifact naming no route at all.
 */
final class UnrecoveredRules
{
    public function __construct(
        private readonly DeclaredFields $declared,
    ) {}

    /** A field whose rules no path could read, so the recovery gave up on it entirely. */
    public function unrecoverable(RouteContext $context, string $field, string $message): void
    {
        $this->report($context, $field, Severity::Info, 'validation.rule-unrecoverable', $message, RulesHarvestingVisitor::UNRECOVERABLE_HELP);
    }

    /** A field that recovered some of its rules and widened past values it could not read. */
    public function widened(RouteContext $context, string $field, string $message): void
    {
        $this->report($context, $field, Severity::Info, 'validation.rule-values-unread', $message, RulesHarvestingVisitor::WIDENED_HELP);
    }

    /**
     * The severity and the code travel from the callers above rather than being chosen here, so the
     * diagnostics-reference scanner reads both off a literal argument list ({@see tools/diagnostic-codes.php}).
     */
    private function report(RouteContext $context, string $field, Severity $severity, string $code, string $message, string $help): void
    {
        if ($this->declared->publishes($field)) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: $severity,
            code: $code,
            message: $message,
            routeSignature: $context->route->signature(),
            help: $help,
        ));
    }
}
