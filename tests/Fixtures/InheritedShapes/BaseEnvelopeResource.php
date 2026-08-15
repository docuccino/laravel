<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InheritedShapes;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The app-wide envelope, declared once on a base resource the way an app with a house style does.
 * `$wrap` is static, so every subclass answers with this value while naming it nowhere.
 */
abstract class BaseEnvelopeResource extends JsonResource
{
    public static $wrap = 'envelope';
}
