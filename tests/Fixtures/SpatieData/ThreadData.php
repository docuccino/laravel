<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A thread whose named constructors build replies of its own class with spatie's wrap taken off, and
 * hand out the thread itself as built. The unwrapped instances are values the thread HOLDS — one passed
 * straight in, one returned by a closure rather than by the method — so the thread is still sent in its
 * envelope.
 */
final class ThreadData extends Data
{
    /**
     * @param  list<ThreadData>  $replies
     */
    public function __construct(
        public string $body,
        public array $replies = [],
    ) {}

    public static function answered(string $body, string $answer): self
    {
        return new self($body, [(new self($answer))->withoutWrapping()]);
    }

    /**
     * @param  list<string>  $replies
     */
    public static function thread(string $body, array $replies): self
    {
        $replies = array_map(function (string $reply): self {
            return (new self($reply))->withoutWrapping();
        }, $replies);

        return new self($body, $replies);
    }
}
