<?php

declare(strict_types=1);

namespace Workbench\App\Data;

use JsonSerializable;
use Workbench\App\Http\Middleware\SubmittedAtAlwaysSent;

/**
 * One submitted form entry. `submittedAt` is sent only where there is one, which is what makes it
 * OPTIONAL on the wire rather than nullable-and-always-there — the distinction a `required` list is
 * the only place in a document to record.
 *
 * Versions before 2026-09-01 always sent the key, null and all. {@see SubmittedAtAlwaysSent} is the
 * runtime half that puts it back for a caller pinned that far; Docuccino compiles the declarative half
 * and never reads or runs this.
 */
final class FormEntryData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $label,
        public ?string $submittedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $entry = ['id' => $this->id, 'label' => $this->label];

        return $this->submittedAt === null ? $entry : [...$entry, 'submittedAt' => $this->submittedAt];
    }
}
