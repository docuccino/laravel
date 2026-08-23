<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Malformed;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Webhook;

/**
 * A webhook class carrying one attribute whose arguments don't fit its constructor, beside the
 * `#[Webhook]` that must still publish it.
 */
/* @phpstan-ignore-next-line argument.type — the wrong argument type IS the fixture */
#[Group(123)]
#[Webhook('malformed.reported')]
final readonly class Reported {}
