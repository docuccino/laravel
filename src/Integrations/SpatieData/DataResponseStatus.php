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
 * Resolves the success status(es) a Data class documents, from two sources in order: the class's own
 * `calculateResponseStatus()` override, else the default spatie's `ResponsableData` supplies —
 * `201 Created` for a POST, `200 OK` for anything else. The override's return types come from the engine,
 * which folds plain ints, class constants (`Response::HTTP_CREATED`) and enum constants to int literals.
 * Several folded literals (a `$x ? 201 : 200`, or multiple return sites) are each documented with the same
 * body, matching runtime truth. A computed status leaves the default 200 and earns an info diagnostic —
 * nothing is executed and nothing is guessed.
 *
 * Only a real override counts: the inherited trait method reports the vendor trait's file, so comparing
 * files against the Data class's own tells the two apart.
 */
final class DataResponseStatus implements ResponseStatusResolver
{
    private const METHOD = 'calculateResponseStatus';

    /** The concern that supplies the default; its file identifies an unoverridden inherited method. */
    private const CONCERN = 'Spatie\\LaravelData\\Concerns\\ResponsableData';

    public function resolveStatuses(RouteContext $context, string $fqcn): array
    {
        if (! class_exists($fqcn) || ! DataClassReflector::isResponsable($fqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($fqcn);
        $override = self::override($reflection);

        if ($override === null) {
            // No override, so spatie's concern is what runs. Only POST is worth an opinion: 200 is already
            // the documented default, so staying quiet there leaves the rest of the chain free to answer.
            return self::inheritsSpatieDefault($reflection) && $context->httpMethod() === 'post' ? [201] : [];
        }

        [$file, $line] = $override;
        $analysis = $context->engine->analyzeAction(new ActionRef($file, $fqcn, self::METHOD, $line));
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
     * Whether the class takes `calculateResponseStatus()` straight from spatie's concern, which is when
     * the vendor default — `201` for a POST, `200` otherwise — is the runtime truth. A trait-provided
     * method reports the trait's own file, so matching that against the concern's file is exact: a class
     * satisfying the response contract by hand is left alone rather than assumed to follow the default.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private static function inheritsSpatieDefault(ReflectionClass $reflection): bool
    {
        if (! trait_exists(self::CONCERN) || ! $reflection->hasMethod(self::METHOD)) {
            return false;
        }

        return $reflection->getMethod(self::METHOD)->getFileName() === (new ReflectionClass(self::CONCERN))->getFileName();
    }

    /**
     * The file and line of the class's OWN `calculateResponseStatus()` declaration, or null when it only
     * inherits one. The trait-provided method reports the vendor trait's file, so a same-file declaration
     * is the tell; a Data class that doesn't inherit the trait at all has no method to find.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return array{string, int}|null
     */
    private static function override(ReflectionClass $reflection): ?array
    {
        if (! $reflection->hasMethod(self::METHOD)) {
            return null;
        }

        $method = $reflection->getMethod(self::METHOD);
        $file = $method->getFileName();
        if ($file === false || $file !== $reflection->getFileName()) {
            return null;
        }

        $line = $method->getStartLine();

        return [$file, $line === false ? 0 : $line];
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
