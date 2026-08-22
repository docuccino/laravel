<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose primary KEY carries an unrecognised custom caster — the resolver falls back to the
 * declared key type rather than giving no answer. Only ever reflected — never queried.
 *
 * @property int $id
 */
final class Turnstile extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => CustomCaster::class,
    ];
}
