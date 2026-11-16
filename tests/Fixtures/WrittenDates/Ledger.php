<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model beside {@see LedgerEntryData} whose two dates both name the epoch: a `timestamp` cast writes the
 * integer `getTimestamp()` returns, a `datetime:U` cast the same digits as a string. Never queried.
 *
 * @property int $id
 * @property int $settled_at
 * @property Carbon $reconciled_at
 */
final class Ledger extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'settled_at' => 'timestamp',
        'reconciled_at' => 'datetime:U',
    ];
}
