<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SerialisedKeys;

/** Initialised by a default, by promotion and implicitly; `note` alone may be left unset, and so unsent. */
final class WidgetStock
{
    public ?int $reorder_level = null;

    public ?string $note;

    /** @var string|null */
    public $legacy;

    public function __construct(
        public int $id,
        public ?string $colour = null,
        public int $quantity = 1,
    ) {}
}
