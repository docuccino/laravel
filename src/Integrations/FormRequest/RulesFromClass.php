<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionRef;
use ReflectionClass;

/**
 * Analyses a class's `rules()` into a {@see RuleSet} without executing it. The shared recovery tail for
 * the FormRequest and laravel-actions integrations, which differ only in how they resolve which class
 * carries the `rules()`.
 *
 * Two complementary passes: the literal path reads `rules()` as a constant array shape
 * ({@see ShapeToRuleSet}) for pipe-string and array-of-string rules; the descriptor path traces the
 * returned array with AST constant folding ({@see RulesMethodVisitor} + {@see ConstValueToRules}) to
 * recover `Rule::enum(…)`/`Rule::in(…)` factories, which the array-shape stage collapses to bare objects.
 * The descriptor path wins per field, being strictly more complete for the fields it recovers.
 *
 * A field present in the array but recovered by neither path raises a `validation.rule-unrecoverable` info
 * diagnostic, so a closure or an undocumented custom rule object never vanishes silently — worded for
 * whether the field is omitted outright or kept by another producer minus its constraints. A field that
 * recovered SOME of its rules and widened past values it could not read raises
 * `validation.rule-values-unread` instead: what it publishes is true, and quieter than the code.
 */
final class RulesFromClass
{
    public function __construct(
        private readonly ShapeToRuleSet $shapes = new ShapeToRuleSet,
    ) {}

    /**
     * @param  list<string>  $documentedElsewhere  fields another producer already documents (e.g. a spatie
     *                                             Data property typed as an upload); unrecoverable rules
     *                                             for these aren't an omission, so they're diagnosed as a
     *                                             loss of constraints instead.
     */
    public function analyse(RouteContext $context, string $class, array $documentedElsewhere = []): ?RuleSet
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        // Record the file BEFORE the method-presence bail (design §10): adding a `rules()` to a
        // warm-cached route's FormRequest must invalidate its fragment, and the analysis-driven
        // dependency below only fires once the method exists.
        $file = $reflection->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }

        if (! $reflection->hasMethod('rules')) {
            return null;
        }

        $line = $reflection->getMethod('rules')->getStartLine();
        $ref = new ActionRef((string) $reflection->getFileName(), $class, 'rules', $line > 0 ? $line : 0);

        // (1) Literal path — a constant array shape. Also what the deterministic stub engine scripts, so
        // the workbench golden's FormRequest body comes from here.
        $analysis = $context->engine->analyzeAction($ref);
        $context->recordDependencyFiles($analysis->dependencyFiles);
        $shapeFields = [];
        foreach ($analysis->returns as $return) {
            $shapeFields = $this->shapes->convert($return->type)->fields;
            break;
        }

        // (2) Descriptor path — trace the returned array with constant folding.
        $visitor = new RulesMethodVisitor;
        $report = $context->engine->trace($ref, $visitor);
        $context->recordDependencyFiles([...$report->dependencyFiles, ...$visitor->dependencyFiles()]);
        $traceFields = $visitor->ruleSet()->fields;

        foreach ($visitor->unrecoverableFields() as $field) {
            if (isset($shapeFields[$field]) || isset($traceFields[$field])) {
                continue;
            }

            // Another producer documenting the field changes WHAT was lost — its shape survives, its
            // constraints don't — but not that something was lost, so it's reported either way.
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-unrecoverable',
                message: in_array($field, $documentedElsewhere, true)
                    ? sprintf('Validation field "%s" on %s has no statically recoverable rules; it is documented from its type alone, without the constraints they state.', $field, $class)
                    : sprintf('Validation field "%s" on %s has no statically recoverable rules; it is omitted from the request schema.', $field, $class),
                help: RulesHarvestingVisitor::UNRECOVERABLE_HELP,
            ));
        }

        foreach ($visitor->widenedFields() as $field) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-values-unread',
                message: sprintf('Validation field "%s" on %s states values this build cannot read, so that constraint is left off the request schema; the rest of its rules are documented.', $field, $class),
                help: RulesHarvestingVisitor::WIDENED_HELP,
            ));
        }

        $merged = [...$shapeFields, ...$traceFields];

        return $merged === [] ? null : new RuleSet($merged);
    }
}
