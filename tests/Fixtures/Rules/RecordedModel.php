<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Illuminate\Database\Eloquent\Model;

/** A model whose constructor is application code a build must never run. */
final class RecordedModel extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        CallRecorder::record();

        parent::__construct($attributes);
    }
}
