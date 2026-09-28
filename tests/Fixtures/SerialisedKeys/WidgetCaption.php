<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SerialisedKeys;

use JsonSerializable;

/** States its own JSON form, which leaves `caption` out while it is null. */
final class WidgetCaption implements JsonSerializable
{
    public function __construct(
        public int $id,
        public ?string $caption = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->caption === null ? ['id' => $this->id] : ['id' => $this->id, 'caption' => $this->caption];
    }
}
