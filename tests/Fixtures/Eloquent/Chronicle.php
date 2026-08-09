<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that overrides `serializeDate()`, so every date attribute serialises in a bespoke wire
 * format that is not statically knowable. Its `published_at` (datetime cast) and the framework
 * timestamp columns are therefore documented as plain strings (no `format`), with an info diagnostic.
 * Documented idiomatically with `@property` tags; only ever reflected — never queried.
 *
 * @property int $id The chronicle id.
 * @property string $title The chronicle title.
 */
final class Chronicle extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('d/m/Y');
    }
}
