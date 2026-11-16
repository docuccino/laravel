<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Symfony\Component\HttpFoundation\Response;

/** Symfony's own response, made a 202 by its subclass. */
class AcceptedResponse extends Response
{
    public function __construct()
    {
        parent::__construct('', 202);
    }
}
