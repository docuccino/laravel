<?php

declare(strict_types=1);

namespace Workbench\App\Data;

/**
 * A plain data object whose shape the schema chain hoists to a reusable component. `publishedAt` is
 * assigned only once the form is published, so until then `json_encode` leaves the key out.
 */
final class FormData
{
    public function __construct(
        public int $id,
        public string $title,
        ?string $publishedAt,
    ) {
        if ($publishedAt !== null) {
            $this->publishedAt = $publishedAt;
        }
    }

    public ?string $publishedAt;
}
