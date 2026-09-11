<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionClass;

/**
 * Resolves the success status(es) a Data class documents, from two sources in order: an application's
 * `calculateResponseStatus()`, else the default spatie's `ResponsableData` supplies — `201 Created`
 * for a POST, `200 OK` for anything else. The override's return types come from the engine,
 * which folds plain ints, class constants (`Response::HTTP_CREATED`) and enum constants to int literals.
 * Several folded literals (a `$x ? 201 : 200`, or multiple return sites) are each documented with the same
 * body — unless the choice between them is one this route already settles, which
 * {@see RouteConditionalStatus} narrows to the single status the route takes. A computed status leaves the
 * default 200 and earns an info diagnostic — nothing is executed and nothing is guessed.
 *
 * Which of the two sources applies is decided by the FILE the method is written in, compared against the
 * vendor concern's — never against the Data class's own. A status calculation is as often written once on
 * an application's base class or in a shared trait as it is on the class returned, and reading "some other
 * file" as "no override" documents the vendor default where the server sends something else.
 */
final class DataResponseStatus implements ResponseStatusResolver
{
    private const METHOD = 'calculateResponseStatus';

    /** The concern that supplies the default; its file is the one declaration that is spatie's. */
    private const CONCERN = 'Spatie\\LaravelData\\Concerns\\ResponsableData';

    public function resolveStatuses(RouteContext $context, string $fqcn): array
    {
        if (! class_exists($fqcn) || ! DataClassReflector::isResponsable($fqcn)) {
            return [];
        }

        $reflection = new ReflectionClass($fqcn);

        // The answer can be written anywhere in the hierarchy, so the whole hierarchy is what it depends
        // on — recorded before any bail, since ADDING an override to a base has to retire the fragment
        // (design §10). Over-keying is a cost; under-keying serves a stale status.
        $context->recordDependencyFiles(DeclarationFiles::of($fqcn));

        $method = $reflection->hasMethod(self::METHOD) ? $reflection->getMethod(self::METHOD) : null;
        $file = $method === null ? false : $method->getFileName();

        if ($method === null || $file === false) {
            // Two different nothings, and neither is the vendor default. No method at all is a class that
            // renders itself without spatie's concern, so there is no default to inherit; a declaration
            // with no file is one nothing can read. Both leave the documented 200 alone rather than
            // guessing 201 — and neither earns a diagnostic, the second because only a PHP-internal
            // method answers that way and no Data class can be one.
            return [];
        }

        if ($file === self::concernFile()) {
            // Spatie's own body is what runs: `$request->isMethod(POST) ? 201 : 200`. Only POST is worth
            // an opinion — 200 is already the documented default, so staying quiet there leaves the rest
            // of the chain free to answer.
            return $context->httpMethod() === 'post' ? [201] : [];
        }

        // Anything else is the application's own, wherever it chose to write it.
        $line = $method->getStartLine();
        $line = $line === false ? 0 : $line;
        $owner = $method->getDeclaringClass()->getName();

        $analysis = $context->engine->analyzeAction(new ActionRef($file, $owner, self::METHOD, $line));
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

            // One status is already right. Several are both true only where the route itself doesn't
            // settle the choice, and {@see RouteConditionalStatus} is what asks whether it does.
            return count($statuses) === 1 ? $statuses : self::narrowToRoute($context, $file, $owner, $line, $statuses);
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'spatie-data.response-status-unresolved',
            // Named for the class that WROTE the method, plus the one it answers for where those differ:
            // a base's calculation is the file the author has to open, and the Data class is how they
            // recognise the endpoint it reached.
            message: $owner === $fqcn
                ? sprintf('%s::calculateResponseStatus() does not fold to constant status(es); the success response is documented as 200.', $fqcn)
                : sprintf('%s::calculateResponseStatus(), inherited by %s, does not fold to constant status(es); the success response is documented as 200.', $owner, $fqcn),
            help: 'Return one or more constant ints (e.g. `return 201;`, a constant like Response::HTTP_CREATED, or a ternary whose arms are both constants) so the status can be documented; a computed status cannot be resolved statically.',
        ));

        return [];
    }

    /**
     * The one status THIS route takes, when the override reduces to a route-name decision
     * ({@see RouteConditionalStatus}); the folded set unchanged for every other shape, including one that
     * genuinely answers two statuses on a fact the build cannot see.
     *
     * The narrowed status must be one the return-type fold also saw. The two read the same override
     * through different grammars — the engine off the return type, the trace off the AST — so a status
     * only one of them recovered means they were not reading the same code, and the union is the honest
     * answer.
     *
     * @param  list<int>  $statuses
     * @return list<int>
     */
    private static function narrowToRoute(RouteContext $context, string $file, string $owner, int $line, array $statuses): array
    {
        $fold = new RouteConditionalStatus;
        $context->traceFrom(new ActionRef($file, $owner, self::METHOD, $line), $fold);

        $status = $fold->statusFor($context->route->name);

        return $status !== null && in_array($status, $statuses, true) ? [$status] : $statuses;
    }

    /**
     * The file spatie's own `calculateResponseStatus()` is written in, or null where the concern is not
     * installed and so cannot be what a method found here came from.
     *
     * This is the whole vendor-versus-application test. A trait-provided method reports the trait's file
     * while reporting the USING class as its declarer, and an inherited one reports the parent's file —
     * so the file is the only thing that tells spatie's body from an application's, and comparing it to
     * the Data class's own file answers a different question entirely.
     */
    private static function concernFile(): ?string
    {
        if (! trait_exists(self::CONCERN)) {
            return null;
        }

        $file = (new ReflectionClass(self::CONCERN))->getFileName();

        return $file === false ? null : $file;
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
