<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/** A response an upstream service answered with, relayed to the client as it came. */
class RelayedResponseException extends HttpResponseException
{
    public function __construct(Response $response, public readonly string $upstream = 'billing')
    {
        parent::__construct($response);
    }
}
