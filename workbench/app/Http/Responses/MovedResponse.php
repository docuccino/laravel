<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/** A redirect that is always permanent, its status named by the framework's constant. */
class MovedResponse extends RedirectResponse
{
    public function __construct(string $url)
    {
        parent::__construct($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
