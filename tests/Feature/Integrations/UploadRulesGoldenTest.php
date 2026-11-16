<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\UploadRules\MediaController;
use Docuccino\Laravel\Tests\Fixtures\UploadRules\StoreMediaRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/*
 * Uploads bounded by the framework's dimension and file builders — dimensions as a keyed array, set one by one,
 * handed to an image file rule, and a file rule's types and size — locked in emitted bytes. No golden held one.
 */
function uploadRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-media', [MediaController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [MediaController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreMediaRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreMediaRequest::class))->getFileName(),
            StoreMediaRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits uploads bounded by the framework builders byte-identical to their committed golden', function (): void {
    assertGolden('upload-rules.uir.json', (new UirEmitter)->emit(uploadRulesBuild()->document));
});
