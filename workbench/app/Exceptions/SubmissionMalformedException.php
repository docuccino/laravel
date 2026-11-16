<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** An application's own refusal, whose constructor fixes the status it answers with. */
final class SubmissionMalformedException extends HttpException
{
    public function __construct(string $message = 'The submission is malformed.')
    {
        parent::__construct(400, $message);
    }
}
