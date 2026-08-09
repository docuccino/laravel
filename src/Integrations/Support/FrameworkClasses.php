<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * FQCNs of framework classes referenced by string across the adapter (never `use`d — they may be
 * absent from the analysed app). One home for a string that would otherwise be re-declared per
 * consumer. Lives under `Integrations\Support` so integrations can reference it through the public
 * extension surface (arch-enforced) rather than each inlining a private copy.
 */
final class FrameworkClasses
{
    /** Illuminate's JSON response wrapper — the `JsonResponse<payload>` type integrations unwrap. */
    public const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';
}
