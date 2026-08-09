<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model documented ONLY via its Eloquent floor sources — no `@property` docblock, so the engine
 * reports no columns and the whole column universe comes from the adapter's floor union: a `$casts`
 * key is a typed column, a `$dates` entry is a date-time column, and a `$fillable`-only name is a
 * permissive column at lowered confidence. `$hidden` still filters a floor column (`secret`). Only
 * ever reflected.
 */
final class Ledger extends Model
{
    /** Scoped to its floor sources — no timestamp columns to keep the floor-union test focused. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @var list<string>
     */
    protected $dates = ['posted_at'];

    /**
     * @var list<string>
     */
    protected $fillable = ['reference', 'amount', 'notes'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
        'secret' => 'string',
    ];
}
