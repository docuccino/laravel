<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

/**
 * A form entry omits `submittedAt` where it has not been submitted, and it did not always: before
 * 2026-09-01 the key was always there, null where there was nothing to say. Callers pinned before that
 * still expect to find it, so it goes back on the way out — recursively, because the shape turns up
 * nested and inside collections, not only as a bare object.
 */
final class SubmittedAtAlwaysSent implements ApiChange
{
    public function since(): string
    {
        return '2026-09-01';
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function downgrade(array $body): array
    {
        // An entry is a map carrying `label`; the list around it is not, and neither is anything else
        // in the body. A migration that rewrote every map it met is the production failure mode this
        // stands in for, so it is written the way one has to be.
        if (array_key_exists('label', $body)) {
            return [...$body, 'submittedAt' => $body['submittedAt'] ?? null];
        }

        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = $this->downgrade($value);
            }
        }

        return $body;
    }
}
