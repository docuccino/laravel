<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Docuccino\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model whose deny-list reaches past its columns: an appended accessor and an eager-loaded relation
 * are named in `$hidden` too. `HasAttributes::attributesToArray()` filters appends through
 * `getArrayableItems()` and `relationsToArray()` filters relations through the same list, so the
 * server returns none of the three. Only ever reflected — never queried.
 *
 * A relation is filtered under the name it is loaded by (`auditTrail`), not the snake key it
 * serialises as — `relationsToArray()` snake-cases only after `getArrayableItems()` has run — so
 * `strong_room` in `$hidden` hides nothing and its relation still serialises.
 *
 * The class-level `#[Hidden]` subtracts alongside `$hidden`, and here it names a third append.
 *
 * @property int $id The strongbox id.
 * @property string $label The strongbox label.
 * @property string $combination The combination — hidden.
 */
#[Hidden('display_name')]
final class Strongbox extends Model
{
    /** No timestamp columns, to keep the deny-list assertions focused. */
    public $timestamps = false;

    /**
     * A column, an appended accessor, and an eager-loaded relation — plus a snake-cased spelling of a
     * relation, which Laravel does not match.
     *
     * @var list<string>
     */
    protected $hidden = ['combination', 'internal_note', 'auditTrail', 'strong_room'];

    /**
     * @var list<string>
     */
    protected $appends = ['display_name', 'internal_note', 'public_note'];

    /**
     * @var list<string>
     */
    protected $with = ['auditTrail', 'strongRoom', 'keeper'];

    /** The appended accessor the class-level `#[Hidden]` keeps out of the payload. */
    public function getDisplayNameAttribute(): string
    {
        return ucfirst($this->label);
    }

    /** The append that survives the deny-list. */
    public function getPublicNoteAttribute(): string
    {
        return $this->label;
    }

    /** The appended accessor `$hidden` keeps out of the payload. */
    public function getInternalNoteAttribute(): string
    {
        return $this->combination;
    }

    /**
     * The eager-loaded relation `$hidden` keeps out of the payload.
     *
     * @return HasMany<Post, $this>
     */
    public function auditTrail(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The eager-loaded relation whose SNAKE spelling is in `$hidden`, which hides nothing.
     *
     * @return HasMany<Post, $this>
     */
    public function strongRoom(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
