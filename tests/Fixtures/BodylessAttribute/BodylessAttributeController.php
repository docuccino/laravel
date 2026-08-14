<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\BodylessAttribute;

use Docuccino\Attributes\Response;

/**
 * Names the same body under a status that forbids one and a status that allows one, so a test can tell
 * "dropped because of the status" apart from "never resolved in the first place".
 */
final class BodylessAttributeController
{
    #[Response(status: 204, type: DiscardedBody::class)]
    public function destroy(): void {}

    #[Response(status: 200, type: DiscardedBody::class)]
    public function show(): void {}
}
