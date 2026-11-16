<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\Response;

/** A plain response that IS a 410: its constructor hands the status to the parent, and nobody else does. */
class GoneResponse extends Response
{
    public function __construct()
    {
        parent::__construct('', 410);
    }

    public static function make(): static
    {
        return new static;
    }
}
