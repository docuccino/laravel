<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

/**
 * A model under the {@see Sundial} `serializeDate()` override whose only date attributes name their OWN
 * wire format. Laravel formats such a column with the cast parameter and never reaches the hook, so
 * nothing here lost a format: each column publishes what its parameter writes, and the model earns no
 * date-serialisation notice at all. One pattern is an ISO shape a `format` keyword names and one is
 * bespoke, so the two publish differently while giving up nothing either way. Only ever reflected.
 *
 * @property int $id The metronome id.
 * @property string $title The metronome title.
 */
final class Metronome extends Sundial
{
    /** No timestamp columns, so the parameterised casts are the only date attributes there are. */
    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'beat_on' => 'date:Y-m-d',
        'chimed_on' => 'date:d/m/Y',
    ];
}
