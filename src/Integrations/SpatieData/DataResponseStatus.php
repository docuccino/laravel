<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionClass;

/**
 * Resolves the HTTP success status(es) a spatie Data class documents when it overrides
 * `calculateResponseStatus()` (spatie's `ResponsableData` returns 200 by default; a `Data` subclass
 * may override it to 201/202/…). The override's return TYPE(s) are read from the engine, which folds
 * plain-int, class-constant (`Response::HTTP_CREATED`) and enum-constant returns to `int` literals:
 *
 *  - a single folded status replaces the inferred 200;
 *  - a conditional/ternary whose arms all fold — `$x ? 201 : 200`, or several `return` sites — folds
 *    to MULTIPLE literals (a union of literals per return site is peeled), and EACH is documented
 *    (the same body under each status, matching runtime truth);
 *  - a genuinely dynamic/computed status (a widened `int`, a non-constant expression) leaves the
 *    default 200 in place and emits an info diagnostic (never a guessed status). Nothing is executed.
 *
 * Only an OVERRIDE counts: the spatie trait's default method reports the vendor trait's file, so a
 * file-identity check against the Data class distinguishes a genuine override from the inherited
 * default, and a plain Data class (no override) is a no-op with no diagnostic.
 */
final class DataResponseStatus implements ResponseStatusResolver
{
    private const METHOD = 'calculateResponseStatus';

    public function resolveStatuses(RouteContext $context, string $fqcn): array
    {
        if (! DataClassReflector::isData($fqcn) || ! class_exists($fqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($fqcn);
        if (! $reflection->hasMethod(self::METHOD)) {
            return [];
        }

        $method = $reflection->getMethod(self::METHOD);
        $methodFile = $method->getFileName();

        // A trait-provided (non-overridden) method reports the trait's file, not the class's; only an
        // override declared in the Data class's own file is a documentable status.
        if ($methodFile === false || $methodFile !== $reflection->getFileName()) {
            return [];
        }

        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef($methodFile, $fqcn, self::METHOD, $line === false ? 0 : $line));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        $statuses = [];
        $foldable = true;
        foreach ($analysis->returns as $return) {
            $folded = self::foldIntLiterals($return->type);
            if ($folded === null) {
                $foldable = false;

                continue;
            }
            $statuses = [...$statuses, ...$folded];
        }

        $statuses = array_values(array_unique($statuses));

        // Every return site must fold, and at least one status recovered — a widened/computed arm
        // leaves the whole override unresolved (we never document a partial or guessed status).
        if ($foldable && $statuses !== []) {
            sort($statuses);

            return $statuses;
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'spatie-data.response-status-unresolved',
            message: sprintf('%s::calculateResponseStatus() does not fold to constant status(es); the success response is documented as 200.', $fqcn),
            help: 'Return one or more constant ints (e.g. `return 201;`, a constant like Response::HTTP_CREATED, or a ternary whose arms are both constants) so the status can be documented; a computed status cannot be resolved statically.',
        ));

        return [];
    }

    /**
     * The int literal(s) a return-site type folds to: a single `LiteralT` int, or a `UnionT` whose
     * members are ALL int literals (a `$x ? 201 : 200` return site translates to a union of literals).
     * Null when any part is not a constant int — the caller then leaves the default in place.
     *
     * @return list<int>|null
     */
    private static function foldIntLiterals(DType $type): ?array
    {
        if ($type instanceof LiteralT) {
            return is_int($type->value) ? [$type->value] : null;
        }

        if ($type instanceof UnionT) {
            $out = [];
            foreach ($type->members as $member) {
                if (! $member instanceof LiteralT || ! is_int($member->value)) {
                    return null;
                }
                $out[] = $member->value;
            }

            return $out;
        }

        return null;
    }
}
