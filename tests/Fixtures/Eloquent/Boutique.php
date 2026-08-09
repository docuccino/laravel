<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\App\Enums\WidgetStatus;

/**
 * A model exercising accessors, the built-in `As*` class casts, and `$with` eager loads. Documented
 * idiomatically with `@property` tags (its column attributes are magic); only ever reflected.
 *
 * - `full_label` is an `$appends` accessor — a classic `getFullLabelAttribute()` types the append.
 * - `options` carries an `array` cast, but a classic `getOptionsAttribute()` accessor OVERRIDES it,
 *   so the accessor's type wins and the cast is skipped (mirroring HasAttributes).
 * - `tags` uses the `AsCollection` class cast (→ array); `kinds` uses `AsEnumCollection` of a backed
 *   enum (→ an array of that enum's values); `secret` uses a custom `CastsAttributes` caster (typed by
 *   its `get()` return type, recovered by the engine).
 * - `$with` eager-loads a to-many (`posts`) and a to-one (`owner`) relation, which serialise on every
 *   response as nested model schemas.
 *
 * @property int $id The boutique id.
 * @property string $sku The stock-keeping unit.
 */
final class Boutique extends Model
{
    /** No timestamp columns, to keep the accessor / cast / $with assertions focused. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $appends = ['full_label'];

    /**
     * @var list<string>
     */
    protected $with = ['posts', 'owner'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'options' => 'array',
        'tags' => AsCollection::class,
        'kinds' => AsEnumCollection::class.':'.WidgetStatus::class,
        'secret' => CustomCaster::class,
    ];

    /** An appended attribute typed by a classic accessor. */
    public function getFullLabelAttribute(): string
    {
        return $this->sku;
    }

    /** A classic accessor overriding the `options` column (its `array` cast is skipped). */
    public function getOptionsAttribute(): string
    {
        return '';
    }

    /**
     * An `Attribute` accessor — its get closure (not the method's `Attribute` return type) is what
     * {@see AccessorReader} locates by line for the engine to analyse.
     *
     * @return Attribute<string, never>
     */
    public function nickname(): Attribute
    {
        return Attribute::make(get: fn (mixed $value): string => (string) $this->sku);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
