<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorized by a conventional policy whose abilities come from a trait — the same body written
 * somewhere other than the class the gate resolved to, reached the other way round from {@see Signage}.
 *
 * @property int $id
 */
final class Banner extends Model {}
