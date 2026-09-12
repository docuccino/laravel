<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

/**
 * A model inheriting the {@see Sundial} `serializeDate()` override while publishing no date attribute
 * at all — no timestamps, no date cast, no `$dates` entry. Nothing it publishes lost a `format`, so
 * `eloquent.custom-date-serialization` must stay quiet. Only ever reflected.
 *
 * @property int $id The signpost id.
 * @property string $label The signpost label.
 */
final class Signpost extends Sundial
{
    /** No timestamp columns, so the model has no date attribute for the override to reach. */
    public $timestamps = false;
}
