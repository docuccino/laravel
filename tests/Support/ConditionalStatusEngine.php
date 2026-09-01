<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RouteStatusController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RouteStatusData;
use ReflectionClass;

/**
 * What the analyser answers for the conditional-status Data fixture: the three actions return it, and
 * its `calculateResponseStatus()` folds to `200|201` — the union a route name then settles. The trace is
 * a real walk of the class's own file, so only the return type is scripted. A support class rather than
 * a helper in a test file, because both the resolver suite and a locality row need the same answers.
 */
final class ConditionalStatusEngine
{
    public const SYMBOL = RouteStatusData::class.'::calculateResponseStatus';

    /** The override's body, walked as written. @return callable(TraceVisitor): void */
    public static function trace(): callable
    {
        return TraceScript::forMethod(
            self::file(),
            RouteStatusData::class,
            'calculateResponseStatus',
            ['request' => new ClassT('Illuminate\\Http\\Request')],
        );
    }

    /** The file the override is declared in — what the fold must record as a dependency. */
    public static function file(): string
    {
        return (string) (new ReflectionClass(RouteStatusData::class))->getFileName();
    }

    /** One return site typed as the given statuses: a single literal, or the union a ternary arrives as. */
    public static function folds(int ...$statuses): ActionAnalysis
    {
        $members = array_map(static fn (int $status): DType => new LiteralT($status), $statuses);

        return new ActionAnalysis(
            returns: [new ReturnSite(count($members) === 1 ? $members[0] : UnionT::of($members), new SourceLocation(''))],
        );
    }

    /** The workbench engine with the fixture's three actions and its override scripted over the defaults. */
    public static function make(): StubTypeEngine
    {
        $returnsData = new ActionAnalysis(
            returns: [new ReturnSite(new ClassT(RouteStatusData::class), new SourceLocation(''))],
        );

        return WorkbenchEngine::make(
            analysisOverrides: [
                RouteStatusController::class.'::store' => $returnsData,
                RouteStatusController::class.'::publish' => $returnsData,
                RouteStatusController::class.'::show' => $returnsData,
                self::SYMBOL => self::folds(201, 200),
            ],
            traceOverrides: [self::SYMBOL => self::trace()],
        );
    }

    /** @return callable(): TypeEngine */
    public static function factory(): callable
    {
        return static fn (): TypeEngine => self::make();
    }
}
