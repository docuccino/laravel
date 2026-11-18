<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\DeclaredFields;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Provenance\Source;
use ReflectionClass;

/**
 * The notes a rules recovery owes for a field it could not fully read, and the stand-down both take:
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

    /**
     * A field given up because one of its rules has unknown content, which could loosen the rest. Null
     * where the rule sits in no field a request key names — inside a map's values — so nothing declared
     * can have published it.
     */
    public function opaque(RouteContext $context, ?string $field, string $message): void
    {
        $this->report($context, $field, Severity::Info, 'validation.rule-unrecoverable', $message, RulesHarvestingVisitor::OPAQUE_HELP);
    }

    /**
     * A field documented without a rule it could not read, one that can only refuse values; null as above.
     * Where that rule is one class's object, the class is the notice's source: annotating it answers every
     * field it is on, which is what lets the notices it raises be read as one ({@see UnreadRuleNotices}).
     */
    public function unread(RouteContext $context, ?string $field, string $message, ?string $ruleClass = null): void
    {
        $this->report($context, $field, Severity::Info, 'validation.rule-unrecoverable', $message, RulesHarvestingVisitor::UNREAD_HELP, self::classSource($context, $ruleClass));
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
    private function report(RouteContext $context, ?string $field, Severity $severity, string $code, string $message, string $help, ?Source $source = null): void
    {
        if ($field !== null && $this->declared->publishes($field)) {
            return;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: $severity,
            code: $code,
            message: $message,
            source: $source,
            routeSignature: $context->route->signature(),
            help: $help,
        ));
    }

    /**
     * Where the rule class is written, named by its FQCN. The file joins the route's dependencies, since the
     * notice now carries its path and a warm build must carry the same one a cold build would.
     */
    private static function classSource(RouteContext $context, ?string $class): ?Source
    {
        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $file = (new ReflectionClass($class))->getFileName();
        if ($file === false) {
            return null;
        }

        $context->recordDependencyFiles(DeclarationFiles::of($class));

        return new Source(($context->pathResolver ?? new RootRelativeSourcePathResolver(''))->relative($file), null, $class);
    }
}
