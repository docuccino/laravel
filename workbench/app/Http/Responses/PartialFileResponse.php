<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A file response that is always a partial one. */
class PartialFileResponse extends BinaryFileResponse
{
    public function __construct(string $file)
    {
        parent::__construct($file, 206);
    }
}
