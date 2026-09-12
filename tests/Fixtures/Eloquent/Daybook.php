<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

/**
 * A model bound on a date column whose body publishes no date attribute: every date it has is
 * `$hidden`, and it inherits the {@see Sundial} `serializeDate()` override — so the path parameter is
 * the only place in the document that gives a `format` up. Only ever reflected.
 *
 * `$hidden` covers the framework timestamps AND the `$dates` entry, so it stands for the visibility
 * gate on both of the rungs that reach the date policy. Three suites read it — the policy's own table,
 * this route-binding pair, and the Eloquent notice table — so a change to its columns is a change to
 * all three; give it a sibling rather than reshaping it.
 *
 * @property int $id The daybook id.
 * @property string $title The daybook title.
 */
final class Daybook extends Sundial
{
    /**
     * @var list<string>
     */
    protected $hidden = ['created_at', 'updated_at', 'posted_at'];

    /**
     * @var list<string>
     */
    protected $dates = ['posted_at'];
}
