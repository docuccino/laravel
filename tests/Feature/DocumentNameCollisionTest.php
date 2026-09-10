<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\LedgerController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;

/**
 * The two document-wide name spaces beside `components.*` where two distinct things can quietly claim
 * one name: the default TAG a controller's short name derives, and the OPERATION ID a route publishes.
 *
 * Neither loses anything from the document, so neither renames anything — but both are read by
 * something downstream (a reader grouping the sidebar, a client generator naming a function), so both
 * are reported. Both are read off the finished build rather than as each route passes, which is what
 * keeps them on a warm cache hit where no route runs.
 */
function tagCollisionDocument(callable $routes, ?callable $mutateConfig = null): GenerationResult
{
    $routes(app('router'));
    bindStubEngine();

    return generateDocument($mutateConfig);
}

it('reports two controllers that merged into one default tag, naming both', function (): void {
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
    });

    $reported = diagnosticsCoded($result->diagnostics, 'tags.name-collision');

    expect($reported)->toHaveCount(1)
        // Info, not a warning: nothing is renamed and no shape is lost, and one `Report` group may well
        // be what the author meant. It says what happened and names the two ways out.
        ->and($reported[0]->severity->value)->toBe('info')
        ->and($reported[0]->message)
        ->toContain('"Report"')
        ->toContain(AdminReportController::class)
        ->toContain(ApiReportController::class)
        ->and($reported[0]->help)->toContain('#[Group]')
        ->and($reported[0]->help)->toContain('tags.map');
});

it('names the controllers in FQCN order, not in the order the routes were registered', function (): void {
    // Determinism: which controller the router happened to hold first must not reach the message.
    $forwards = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
    });
    $backwards = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
    });

    expect(diagnosticsCoded($backwards->diagnostics, 'tags.name-collision'))
        ->toEqual(diagnosticsCoded($forwards->diagnostics, 'tags.name-collision'));
});

it('reports nothing for controllers whose short names differ', function (): void {
    // The negative path is the one that decides whether this is usable at all: a diagnostic that fires
    // on an app with no collision is noise on every build.
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-api-ledgers', [LedgerController::class, 'index']);
    });

    expect(diagnosticsCoded($result->diagnostics, 'tags.name-collision'))->toBe([]);
});

it('reports nothing when #[Group] already put one of them somewhere else', function (): void {
    // An explicit group is the author having answered the question, so the tag is no longer a default.
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'grouped']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
    });

    expect(diagnosticsCoded($result->diagnostics, 'tags.name-collision'))->toBe([]);
});

it('reports nothing when the document derives no default tag at all', function (): void {
    $result = tagCollisionDocument(
        static function ($router): void {
            $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
            $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
        },
        static function (array $raw): array {
            $raw['tags']['default_strategy'] = 'none';

            return $raw;
        },
    );

    expect(diagnosticsCoded($result->diagnostics, 'tags.name-collision'))->toBe([]);
});

it('still reports the merged tag on a warm fragment-cache build', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-tags-'.uniqid('', true);
    setBuild('cache.enabled', true);
    setBuild('cache.path', $dir);

    $routes = static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
    };

    $cold = tagCollisionDocument($routes);
    $warm = generateDocument();

    expect(diagnosticsCoded($warm->diagnostics, 'tags.name-collision'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'tags.name-collision'))
        ->not->toBeEmpty();

    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});

it('reports two routes that published one operationId, naming both', function (): void {
    // `controller-method` shortens the controller, so two `ReportController@index` in different
    // namespaces publish one id — which is what a client generator names its function after.
    $result = tagCollisionDocument(
        static function ($router): void {
            $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
            $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
        },
        static function (array $raw): array {
            $raw['representation']['operation_id'] = 'controller-method';

            return $raw;
        },
    );

    $reported = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'),
        static fn ($d): bool => str_contains($d->message, 'ReportController@index'),
    ));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->severity->value)->toBe('warning')
        ->and($reported[0]->message)
        ->toContain('"ReportController@index"')
        ->toContain('zz-admin-reports')
        ->toContain('zz-api-reports')
        ->and($reported[0]->help)->toContain('#[OperationId]');
});

it('reports no duplicate operationId when every route publishes its own', function (): void {
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-api-ledgers', [LedgerController::class, 'index']);
    });

    expect(diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'))->toBe([]);
});

it('still reports the duplicate operationId on a warm fragment-cache build', function (): void {
    // The operationId is on the cached fragment, so this survives where a per-route report would not.
    $dir = sys_get_temp_dir().'/docuccino-opids-'.uniqid('', true);
    setBuild('cache.enabled', true);
    setBuild('cache.path', $dir);

    $routes = static function ($router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);
    };
    $config = static function (array $raw): array {
        $raw['representation']['operation_id'] = 'controller-method';

        return $raw;
    };

    $cold = tagCollisionDocument($routes, $config);
    $warm = generateDocument($config);

    expect(diagnosticsCoded($warm->diagnostics, 'route.duplicate-operation-id'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'route.duplicate-operation-id'))
        ->not->toBeEmpty();

    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});

/**
 * The mint's side of the same name space, end to end. Four paths one application may register side
 * by side used to reduce to one name, because the reduction folded `-`, `_`, camel case and the
 * segment break itself into a capital letter — so the document published one `operationId` four
 * times, and a generated client cannot give one method name to four methods. The names are read off
 * the finished document rather than from the mint, because it is the DOCUMENT that has to hold four.
 */
it('publishes a name of its own for paths that differ only in punctuation', function (): void {
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-user-profile', [LedgerController::class, 'index']);
        $router->get('api/zz-user_profile', [LedgerController::class, 'index']);
        $router->get('api/zz-userProfile', [LedgerController::class, 'index']);
        $router->get('api/zz-user/profile', [LedgerController::class, 'index']);
    });

    $paths = $result->document->toArray()['paths'];

    expect([
        $paths['/api/zz-user-profile']['get']['operationId'],
        $paths['/api/zz-user_profile']['get']['operationId'],
        $paths['/api/zz-userProfile']['get']['operationId'],
        $paths['/api/zz-user/profile']['get']['operationId'],
    ])->toBe([
        'get.api.zz-user-profile',
        'get.api.zz-user__profile',
        'get.api.zz-userProfile',
        'get.api.zz-user.profile',
    ])
        ->and(diagnosticsCoded($result->diagnostics, 'route.duplicate-operation-id'))->toBe([]);
});

/**
 * And every other operation in the same document keeps a name of its own, which is the claim the
 * mint makes and the one a document is the only place to check.
 */
it('publishes as many operationIds as it has operations, all different', function (): void {
    $result = tagCollisionDocument(static function ($router): void {
        $router->get('api/zz-user-profile', [LedgerController::class, 'index']);
        $router->get('api/zz-user/profile', [LedgerController::class, 'index']);
    });

    $ids = [];
    $operations = 0;
    foreach ($result->document->toArray()['paths'] as $item) {
        foreach ($item as $operation) {
            $operations++;
            $ids[] = $operation['operationId'] ?? null;
        }
    }

    expect($operations)->toBeGreaterThan(10)
        ->and(array_filter($ids, static fn (?string $id): bool => $id === null))->toBe([])
        ->and(array_unique($ids))->toHaveCount($operations);
});
