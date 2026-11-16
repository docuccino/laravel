<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

/** One kind of problem, two classes below the framework: always a 409. */
class ConflictProblemResponse extends ProblemResponse
{
    public function __construct(string $detail)
    {
        parent::__construct(['title' => 'Conflict', 'detail' => $detail], 409);
    }
}
