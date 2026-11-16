<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Rfc9457;

use RuntimeException;

/** An error that carries the problem it is answered with, chosen wherever it was thrown. */
final class ProblemException extends RuntimeException
{
    public function __construct(private readonly Problem $problem)
    {
        parent::__construct($problem->detail);
    }

    public function toProblem(): Problem
    {
        return $this->problem;
    }
}
