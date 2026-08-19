<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Closure;
use Docuccino\Laravel\Watch\BuildRunner;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A {@see BuildRunner} that records what it was asked to build and hands control back to the test
 * instead of spawning anything — the seam that makes `docuccino:watch`'s loop testable without a
 * subprocess.
 */
final class ScriptedBuildRunner implements BuildRunner
{
    /** @var list<array{document: string|null, memoryLimit: string|null}> */
    public array $calls = [];

    /**
     * @param  Closure(int): void|null  $onBuild  called with the 1-based build number
     */
    public function __construct(
        private readonly ?Closure $onBuild = null,
        private readonly int $exit = 0,
    ) {}

    public function build(OutputInterface $output, ?string $document, ?string $memoryLimit): int
    {
        $this->calls[] = ['document' => $document, 'memoryLimit' => $memoryLimit];

        if ($this->onBuild !== null) {
            ($this->onBuild)(count($this->calls));
        }

        return $this->exit;
    }
}
