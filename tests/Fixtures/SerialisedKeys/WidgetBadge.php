<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SerialisedKeys;

/** A value object built through its constructor: `icon_url` is null until an icon is uploaded, and always sent. */
final readonly class WidgetBadge
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $pinned,
        public ?string $icon_url,
    ) {}
}
