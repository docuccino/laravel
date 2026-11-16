<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\Response;

/** A response whose constructor sets its status after the parent has set the default one. */
class ExpiredResponse extends Response
{
    public function __construct(string $content = '')
    {
        parent::__construct($content);

        $this->setStatusCode(410);
    }
}
