<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Built by its static factories, each carrying a response of its own. */
class ExportRefusedException extends HttpResponseException
{
    public static function locked(): self
    {
        return new self(response()->json(['error' => 'locked'], 423));
    }

    public static function gone(): static
    {
        return new static(response()->json(['error' => 'gone'], 410));
    }
}
