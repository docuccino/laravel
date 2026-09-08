<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Reachable through two registered parent types and through no exact registration or conventional name,
 * so it resolves by the subclass fallback — the one resolution branch that reads the policy map in
 * order. Deliberately has no `Policies\HoardingPolicy`: the convention is asked first, and finding one
 * would settle the gate before the fallback ran.
 *
 * @property int $id
 */
final class Hoarding extends Model implements Illuminated, Weatherproof {}
