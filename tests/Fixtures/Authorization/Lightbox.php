<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

/**
 * Authorized through its PARENT's `#[UsePolicy]` and through nothing else: no attribute of its own, no
 * registration, and deliberately no conventional `Policies\LightboxPolicy` — every earlier branch has
 * to miss for the resolution to reach the one this model is here for.
 *
 * Laravel 13 resolves it and 12 does not, so what this pins is that the mirror answers wherever the
 * installed framework does, rather than in the dialect of the version it was written against.
 *
 * @property int $id
 */
final class Lightbox extends Totem {}
