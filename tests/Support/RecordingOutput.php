<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Symfony\Component\Console\Output\Output;

/**
 * Console output that keeps every chunk it was handed and the moment it arrived — enough to tell a
 * streamed build from one buffered until it ends, and to read back escapes a real terminal would eat.
 */
final class RecordingOutput extends Output
{
    /** @var list<array{text: string, at: float}> */
    public array $chunks = [];

    public function __construct(bool $decorated = true)
    {
        parent::__construct(self::VERBOSITY_NORMAL, $decorated);
    }

    public function text(): string
    {
        return implode('', array_column($this->chunks, 'text'));
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->chunks[] = ['text' => $message.($newline ? PHP_EOL : ''), 'at' => microtime(true)];
    }
}
