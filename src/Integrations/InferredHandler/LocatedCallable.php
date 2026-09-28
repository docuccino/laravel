<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Inference\CallableRef;
use ReflectionFunction;

/**
 * Where a callable registered on the exception handler is written, the one way every hook locates one: a
 * method-backed closure (an invokable, an `[$obj, 'method']` pair, a first-class callable) by its class and
 * method, since its declaration line is no closure literal; a genuine closure by file and line.
 */
final readonly class LocatedCallable
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $class = null,
        public ?string $method = null,
    ) {}

    /** Null where there is no source to read, or a bound free function with no owning class to name. */
    public static function of(ReflectionFunction $function): ?self
    {
        $file = $function->getFileName();
        $line = $function->getStartLine();
        if ($file === false || $line === false) {
            return null;
        }

        if ($function->isAnonymous()) {
            return new self($file, $line);
        }

        $class = $function->getClosureScopeClass()?->getName();

        return $class === null ? null : new self($file, $line, $class, $function->getName());
    }

    /** Method-backed, as opposed to a genuine anonymous closure. */
    public function isMethod(): bool
    {
        return $this->method !== null;
    }

    public function ref(?string $narrowParameter = null, ?string $narrowType = null, bool $narrowToEvery = false, bool $returnsExceptions = false): CallableRef
    {
        return $this->method !== null
            ? new CallableRef($this->file, $this->class, $this->method, 0, $narrowParameter, $narrowType, $narrowToEvery, $returnsExceptions)
            : new CallableRef($this->file, null, null, $this->line, $narrowParameter, $narrowType, $narrowToEvery, $returnsExceptions);
    }
}
