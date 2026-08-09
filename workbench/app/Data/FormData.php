<?php

declare(strict_types=1);

namespace Workbench\App\Data;

/**
 * A plain data object whose shape the schema chain hoists to a reusable component.
 */
final class FormData
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $publishedAt,
    ) {}
}
