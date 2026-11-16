<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\Response;

/** A response that sets its status only on one branch, so no single status is the class's. */
class BranchingResponse extends Response
{
    public function __construct(string $content = '')
    {
        parent::__construct($content);

        if ($content === '') {
            $this->setStatusCode(204);
        }
    }
}
