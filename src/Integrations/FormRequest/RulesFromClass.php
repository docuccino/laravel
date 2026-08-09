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
 * Analyses a class's `rules()` method into a {@see RuleSet}, without executing anything. The one
 * recovery tail the FormRequest and laravel-actions integrations converge on — they differ only in
 * how they resolve WHICH class carries the `rules()`.
 *
 * Two complementary passes over the same `rules()`:
 *
 * 1. the literal path — `rules()` analysed as a constant array shape ({@see ShapeToRuleSet}),
 *    recovering pipe-string and array-of-string rules;
 * 2. the descriptor path — the returned array traced with AST-level constant folding
 *    ({@see RulesMethodVisitor} + {@see ConstValueToRules}), recovering `Rule::enum(...)` /
 *    `Rule::in(...)` factory descriptors that the array-shape stage collapses to bare objects
 *    (validation §1 — previously dropped silently).
 *
 * The descriptor path wins per field (it is strictly more complete for the fields it recovers). A
 * field present in the array but recovered by neither path raises a `validation.rule-unrecoverable`
 * info diagnostic, so an unrecoverable field (a closure / custom rule object) never vanishes silently.
 */
final class RulesFromClass
{
    public function __construct(
        private readonly ShapeToRuleSet $shapes = new ShapeToRuleSet,
    ) {}

    /**
     * @param  list<string>  $documentedElsewhere  field names another producer already documents (e.g. a
     *                                             spatie Data property typed as an upload); their
     *                                             rules() being unrecoverable is not a real omission, so
     *                                             no `validation.rule-unrecoverable` fires for them.
     */
    public function analyse(RouteContext $context, string $class, array $documentedElsewhere = []): ?RuleSet
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        // Record the FormRequest file BEFORE the method-presence bail (design §10 cache soundness):
        // adding a `rules()` method to a warm-cached route's FormRequest must invalidate its fragment,
        // which the analysis-driven dependency below can only record once the method already exists.
        $file = $reflection->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }

        if (! $reflection->hasMethod('rules')) {
            return null;
        }

        $line = $reflection->getMethod('rules')->getStartLine();
        $ref = new ActionRef((string) $reflection->getFileName(), $class, 'rules', $line > 0 ? $line : 0);

        // (1) Literal path — a constant array shape. Also the path the deterministic stub engine
        // scripts, so the workbench golden's FormRequest body is driven from here.
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
        $context->recordDependencyFiles($report->dependencyFiles);
        $traceFields = $visitor->ruleSet()->fields;

        foreach ($visitor->unrecoverableFields() as $field) {
            if (isset($shapeFields[$field]) || isset($traceFields[$field]) || in_array($field, $documentedElsewhere, true)) {
                continue;
            }
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-unrecoverable',
                message: sprintf('Validation field "%s" on %s has no statically recoverable rules; it is omitted from the request schema.', $field, $class),
                help: 'Its rules are a closure, a custom Rule object, or a Rule::when()/conditional descriptor. Express the field with recoverable rules (string/array rules, Rule::enum(), Rule::in(), …) so it is documented.',
            ));
        }

        $merged = [...$shapeFields, ...$traceFields];

        return $merged === [] ? null : new RuleSet($merged);
    }
}
