<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model carrying BOTH lists: an allow-list it inherited the shape of, narrowed further by a
 * deny-list. `getArrayableItems()` intersects with `$visible` and only then subtracts `$hidden`, so a
 * name in both is hidden — the deny-list is never overridden by the allow-list. An append is subject
 * to the same intersection, so `ranking` (absent from `$visible`) never serialises. Only ever
 * reflected — never queried.
 *
 * @property int $id The showcase id.
 * @property string $name The showcase name — allow-listed, then hidden.
 * @property string $tally The tally, outside the allow-list.
 */
final class Showcase extends Model
{
    /** No timestamp columns, to keep the allow-list assertions focused. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $visible = ['id', 'name', 'badge'];

    /**
     * @var list<string>
     */
    protected $hidden = ['name'];

    /**
     * @var list<string>
     */
    protected $appends = ['badge', 'ranking'];

    /** The append inside the allow-list. */
    public function getBadgeAttribute(): string
    {
        return (string) $this->id;
    }

    /** The append outside it. */
    public function getRankingAttribute(): string
    {
        return (string) $this->id;
    }
}
