<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\Response;

/** A plain response whose constructor hands its parent an array, which Laravel sends as JSON. */
class LockedNoticeResponse extends Response
{
    public function __construct()
    {
        parent::__construct(['error' => 'locked'], 423);
    }
}
