<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A relation the model below takes under another name. */
trait AuditsQuires
{
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'keeper_id');
    }
}

/** One of two traits writing a `caption` accessor; the model below chooses the other. */
trait CaptionsByNumber
{
    /**
     * @return Attribute<int, never>
     */
    public function caption(): Attribute
    {
        return Attribute::make(get: fn (): int => 0);
    }
}

/** The `caption` accessor the model below chooses by `insteadof`. */
trait CaptionsByTitle
{
    /**
     * @return Attribute<string, never>
     */
    public function caption(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): string => (string) ($attributes['title'] ?? ''));
    }
}

/**
 * A model whose relation arrives under a trait alias and whose accessor is chosen between two traits by
 * `insteadof`, with a second accessor written in a trait's own file, sharing its file with a draft model that writes the same method names differently. Each
 * reader has to answer with the body PHP runs for THIS class. Only ever reflected.
 *
 * @property string $title The quire's title.
 * @property int $pages Its page count.
 */
final class Quire extends Model
{
    use AuditsQuires { auditor as keeper; }
    use CaptionsByNumber, CaptionsByTitle {
        CaptionsByTitle::caption insteadof CaptionsByNumber;
    }
    use SummarisesQuires;

    protected function casts(): array
    {
        return ['pages' => 'integer'];
    }
}

/**
 * The neighbour: every name the model above resolves, written again with another meaning.
 *
 * @property string $pages Its pages, as text.
 */
final class QuireDraft extends Model
{
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    /**
     * @return Attribute<bool, never>
     */
    public function caption(): Attribute
    {
        return Attribute::make(get: fn (): bool => false);
    }

    protected function casts(): array
    {
        return ['pages' => 'string'];
    }
}
