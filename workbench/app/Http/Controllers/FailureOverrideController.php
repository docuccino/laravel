<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\Failures\BaggedFailureRequest;
use Workbench\App\Http\Requests\Failures\ConfiguredFailureRequest;
use Workbench\App\Http\Requests\Failures\EnvelopedFailureRequest;
use Workbench\App\Http\Requests\Failures\HiddenRefusalRequest;
use Workbench\App\Http\Requests\Failures\InheritedFailureRequest;
use Workbench\App\Http\Requests\Failures\MalformedFailureRequest;
use Workbench\App\Http\Requests\Failures\ParentFailureRequest;
use Workbench\App\Http\Requests\Failures\RefusalMessageRequest;

/**
 * One action per form request answering a failed validation or authorization its own way. The framework
 * runs the request's hooks before the action, so what each refuses with is what the request is answered with.
 */
final class FailureOverrideController
{
    public function enveloped(EnvelopedFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function inherited(InheritedFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function bagged(BaggedFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function malformed(MalformedFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function parentFailure(ParentFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function configured(ConfiguredFailureRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function refusalMessage(RefusalMessageRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }

    public function hiddenRefusal(HiddenRefusalRequest $request): JsonResponse
    {
        return response()->json(['filed' => true], 201);
    }
}
