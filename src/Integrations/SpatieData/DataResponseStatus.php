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
 * Resolves the success status(es) a Data class documents by overriding `calculateResponseStatus()` —
 * spatie's `ResponsableData` returns 200 unless a subclass says otherwise. The override's return types
 * come from the engine, which folds plain ints, class constants (`Response::HTTP_CREATED`) and enum
 * constants to int literals. Several folded literals (a `$x ? 201 : 200`, or multiple return sites) are
 * each documented with the same body, matching runtime truth. A computed status leaves the default 200
 * and earns an info diagnostic — nothing is executed and nothing is guessed.
 *
 * Only a real override counts: the inherited trait method reports the vendor trait's file, so comparing
 * files against the Data class's own tells the two apart. No override means no work and no diagnostic.
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

        // A trait-provided method reports the trait's file, so only a same-file declaration is an override.
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

        // All-or-nothing: one computed arm leaves the whole override unresolved rather than partial.
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
     * The int literals a return-site type folds to — one `LiteralT`, or a union whose members are all int
     * literals (which is how a `$x ? 201 : 200` arrives). Null if any part isn't a constant int.
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
