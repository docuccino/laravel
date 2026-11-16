<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\ArchiveFileResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\ChunkedResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\EnvelopeResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\FeedResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\NoticeResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\RelayRedirectResponse;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\MembershipController;
use Workbench\App\Http\Responses\PendingJsonResponse;

/**
 * The document an application gets for a response that comes back as the class it is, with no status or
 * body read off it: a package's subclass of each framework response, whose constructor the engine does not
 * read, Symfony's own JSON response handed back by a package's collaborator, and the application's JSON
 * response setting its status on one branch. Each is documented as the framework class it extends.
 * Byte-locked, warm as well as cold.
 *
 * @return array<string, ClassT> action → the class it returns
 */
function bareResponseSubclassReturns(): array
{
    return [
        'show' => new ClassT(EnvelopeResponse::class),
        'legacy' => new ClassT('Symfony\\Component\\HttpFoundation\\JsonResponse'),
        'queue' => new ClassT(PendingJsonResponse::class),
        'portal' => new ClassT(RelayRedirectResponse::class),
        'archive' => new ClassT(ArchiveFileResponse::class),
        'activity' => new ClassT(ChunkedResponse::class),
        'renewals' => new ClassT(FeedResponse::class),
        'notice' => new ClassT(NoticeResponse::class),
    ];
}

/** @return callable(): TypeEngine */
function bareResponseSubclassEngine(): callable
{
    $analyses = [];
    foreach (bareResponseSubclassReturns() as $action => $type) {
        $analyses[MembershipController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function bareResponseSubclassRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(bareResponseSubclassReturns()) as $action) {
            $router->get('api/memberships/'.$action, [MembershipController::class, $action]);
        }
    };
}

it('documents a response that comes back as its own class as the framework class it extends, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(bareResponseSubclassRoutes(), bareResponseSubclassRoutes(), bareResponseSubclassEngine());

    assertGolden('workbench-bare-response-subclass.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the application’s JSON response setting its status on one branch', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseSubclassController.php',
        'App\\Http\\Controllers\\ResponseSubclassController',
        'branchingJson',
    ));

    expect(array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $real->returns))
        ->toBe([(new ClassT('App\\Http\\Responses\\PendingJsonResponse'))->toArray()]);
})->group('fixture');
