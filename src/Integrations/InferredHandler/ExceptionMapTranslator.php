<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionTranslator;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Translates a throw as `$exceptions->map(…)` does before anything renders it (design §6). A translation is
 * taken only where it is the whole answer — one exception every reachable return agrees on — and otherwise
 * the thrown answer stands, with `inferred-handler.too-dynamic` naming the entry.
 */
final class ExceptionMapTranslator implements ExceptionTranslator
{
    private const HTTP_EXCEPTION = 'Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface';

    public function __construct(private readonly HandlerReflector $reflector) {}

    public function translate(ThrownException $exception, RouteContext $context): ?ThrownException
    {
        if ($this->reflector->exceptionMappings() === []) {
            return null;
        }

        // Which entry matches — or whether any does — is a function of the thrown class's ancestry, so a
        // parent it gains or loses re-decides it.
        $context->recordDependencyFiles(DeclarationFiles::of($exception->exceptionFqcn));

        $mapping = $this->reflector->mappingFor($exception->exceptionFqcn);
        if ($mapping === null) {
            return null;
        }

        if ($mapping->target !== null) {
            return $this->noted($context, $mapping, $exception, $exception->as($mapping->target, self::classStatus($mapping->target)));
        }

        $ref = $mapping->ref($exception->exceptionFqcn);
        if ($ref === null) {
            HandlerDeferralLog::recordMapping($context, $mapping->label, $exception->exceptionFqcn);

            return null;
        }

        $analysis = $context->engine->analyzeCallable($ref);
        $context->recordDependencyFiles($analysis->dependencyFiles);

        $outcomes = $this->outcomes($analysis, $mapping, $exception);
        if ($outcomes === null || count($outcomes) !== 1) {
            HandlerDeferralLog::recordMapping($context, $ref->target(), $exception->exceptionFqcn);

            return null;
        }

        $translated = $outcomes[array_key_first($outcomes)];

        return $translated === $exception ? null : $this->noted($context, $mapping, $exception, $translated, $ref->target());
    }

    /**
     * Every exception the mapper can answer with for this throw, keyed by identity — the throw itself where a
     * reachable return hands it back — or null where a reachable return names nothing this build can read.
     *
     * @return array<string, ThrownException>|null
     */
    private function outcomes(ActionAnalysis $analysis, ExceptionMapping $mapping, ThrownException $exception): ?array
    {
        if ($analysis->returns === []) {
            return null;
        }

        $outcomes = [];
        foreach ($analysis->returns as $site) {
            if ($mapping->parameterName !== null && $site->returnsParameter === $mapping->parameterName) {
                $outcomes[$exception->identityKey()] = $exception;
            } elseif ($site->type instanceof UnknownT) {
                return null;
            }
        }

        foreach ($analysis->throws as $throw) {
            $translated = $exception->as($throw->exceptionFqcn, $throw->httpStatusHint);
            $outcomes[$translated->identityKey()] ??= $translated;
        }

        return $outcomes;
    }

    /**
     * The translation, with the author told where its status is one nothing read: an HTTP exception carrying
     * no status of its own, outside the classes the framework table can place.
     */
    private function noted(RouteContext $context, ExceptionMapping $mapping, ThrownException $exception, ThrownException $translated, ?string $target = null): ThrownException
    {
        if ($translated->httpStatusHint === null && FrameworkExceptionTable::match($translated->exceptionFqcn) === null) {
            HandlerDeferralLog::recordMapping($context, $target ?? $mapping->label, $exception->exceptionFqcn);
        }

        return $translated;
    }

    /**
     * The status a class-string target carries, which `map()` builds as `new $to('', 0, $e)` with no status
     * to read: none for an HTTP exception or a class the framework table places, which the tiers classify
     * as they would any throw of it, and the 500 the framework sends for anything else.
     */
    private static function classStatus(string $fqcn): ?int
    {
        return FrameworkExceptionTable::match($fqcn) !== null || is_a($fqcn, self::HTTP_EXCEPTION, true) ? null : 500;
    }
}
