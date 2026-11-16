<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * Columns that reach the wire as they are stored beside ones that don't: a label an accessor rewrites,
 * a secret an encrypting cast hides, a badge appended from nowhere, and the timestamps the framework
 * writes as dates. Never queried.
 *
 * @property int $id
 * @property int $code
 * @property string $label
 * @property string $secret
 */
final class Crate extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $appends = ['badge'];

    /** @var array<string, string> */
    protected $casts = ['code' => 'integer', 'secret' => 'encrypted'];

    public function getLabelAttribute(string $value): string
    {
        return ucfirst($value);
    }

    public function getBadgeAttribute(): string
    {
        return 'crate';
    }
}
