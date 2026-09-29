<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteConstraints;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\WorkflowStep;
use Illuminate\Http\Request;

/**
 * Actions behind optional path segments (`{page?}`), each giving the segment the default the framework
 * documents for it: the action parameter's own, or none for the route's `->defaults()` to supply. Each
 * answers with the value it received, so a test can ask the framework what leaving the segment off sends.
 */
final class OptionalSegmentController
{
    public function page(?string $page = null): array
    {
        return ['page' => $page];
    }

    public function first(string $page = 'first'): array
    {
        return ['page' => $page];
    }

    public function numbered(Request $request, int $page = 3): array
    {
        return ['page' => $page];
    }

    public function listed(array $page = []): array
    {
        return ['page' => $page];
    }

    public function archive(?string $year = null, string $month = '01'): array
    {
        return ['year' => $year, 'month' => $month];
    }

    #[Description(file: 'docs/no-such-description.md')]
    public function undescribed(?string $page = null): array
    {
        return ['page' => $page];
    }

    #[WorkflowStep('browse', order: 1, id: 'open-page')]
    public function stepped(?string $page = null): array
    {
        return ['page' => $page];
    }

    #[PathParameter('page', type: 'int', description: 'The page.')]
    public function declared(int $page = 1): array
    {
        return ['page' => $page];
    }
}
