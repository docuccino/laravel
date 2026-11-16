<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Illuminate\Validation\Rules\File;

/** An application's own file rule, whose constructor is application code a build must never run. */
final class RecordedUpload extends File
{
    public function __construct()
    {
        CallRecorder::record();
    }
}
