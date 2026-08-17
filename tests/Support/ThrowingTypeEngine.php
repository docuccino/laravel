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
use RuntimeException;

/**
 * A {@see TypeEngine} decorator that throws from analyzeAction for one action symbol and delegates
 * everything else — so a test can prove a single exploding route is isolated to a skeleton while
 * its siblings document normally (design §5 per-route try/catch).
 */
final class ThrowingTypeEngine implements TypeEngine
{
    /** @param  ?string  $message  what it throws, for a test that cares about the words and not just the fact */
    public function __construct(
        private readonly TypeEngine $delegate,
        private readonly string $throwingSymbol,
        private readonly ?string $message = null,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        if ($action->symbol() === $this->throwingSymbol) {
            throw new RuntimeException($this->message ?? 'engine blew up analysing '.$this->throwingSymbol);
        }

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
