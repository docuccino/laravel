<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;

/**
 * A {@see TypeEngine} decorator that counts `analyzeAction` calls, so the fragment-cache tests can
 * assert a warm run skips the engine entirely.
 */
final class CountingTypeEngine implements TypeEngine
{
    public int $analyzeCount = 0;

    public function __construct(
        private readonly TypeEngine $delegate,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        $this->analyzeCount++;

        return $this->delegate->analyzeAction($action);
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->delegate->analyzeCallable($callable);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->delegate->classMetadata($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        return $this->delegate->trace($action, $visitor);
    }
}
