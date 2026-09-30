<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource declaring a typed `$forceWrapping` with no value, so the static cannot be read until
 * something assigns it.
 */
final class UnsetForceWrapResource extends JsonResource
{
    public static bool $forceWrapping;
}
