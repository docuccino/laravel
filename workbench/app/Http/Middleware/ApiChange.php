<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

/**
 * One version change's imperative half: the version it shipped in, and how to walk a response body
 * back to the shape a caller pinned before that version still expects.
 *
 * Hand-rolled on purpose. Docuccino executes nothing of the application, so it neither reads nor runs
 * any of this — the workbench is standing in for an application that owns its own migrations runtime.
 */
interface ApiChange
{
    /** The API version this change shipped in. Anyone pinned strictly before it gets downgraded. */
    public function since(): string;

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function downgrade(array $body): array;
}
