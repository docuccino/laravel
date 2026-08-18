<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Closure;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\ReportsBootFailure;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;

/**
 * A {@see TypeEngine} that builds the real engine on the first question and never before it. Every
 * command resolves a TypeEngine before its `handle()` runs, so the analyser used to boot even for a
 * build whose fragments are all warm and which asks it nothing — the whole of that boot, for no
 * answer. The engine still boots ahead of any analysis, so nothing about its own setup changes.
 *
 * {@see identity()} is why this can be wrapped at all: the fragment cache keys on which engine
 * resolved (the adapter's build fingerprint) and computes that key before the first route, so it
 * needs to name the engine without waking it.
 *
 * @internal
 */
final class LazyTypeEngine implements ReportsBootFailure, TypeEngine
{
    private ?TypeEngine $engine = null;

    /**
     * @param  Closure(): TypeEngine  $build
     * @param  string  $identity  what `$build` will resolve to ({@see TypeEngineFactory::engineIdentity()})
     */
    public function __construct(
        private readonly Closure $build,
        private readonly string $identity,
    ) {}

    /** Names the engine this will build, without building it. */
    public function identity(): string
    {
        return $this->identity;
    }

    /**
     * What the built engine reports — null while nothing has asked a question, since a boot that has
     * not happened cannot have failed. Asking is never itself a reason to build one.
     */
    public function bootFailure(): ?string
    {
        return $this->engine instanceof ReportsBootFailure ? $this->engine->bootFailure() : null;
    }

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return $this->engine()->analyzeAction($action);
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->engine()->analyzeCallable($callable);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->engine()->classMetadata($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        return $this->engine()->trace($action, $visitor);
    }

    private function engine(): TypeEngine
    {
        return $this->engine ??= ($this->build)();
    }
}
