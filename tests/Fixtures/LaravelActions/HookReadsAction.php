<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * An action reading one header in every method laravel-actions calls on it while validating, each named
 * after the method, beside one it never calls. Only ever parsed and reflected.
 */
final class HookReadsAction
{
    use AsAction;

    public function afterValidator(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-afterValidator');
    }

    public function authorize(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-authorize');
    }

    public function getAuthorizationFailure(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getAuthorizationFailure');
    }

    public function getValidationAttributes(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationAttributes');
    }

    public function getValidationData(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationData');
    }

    public function getValidationErrorBag(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationErrorBag');
    }

    public function getValidationFailure(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationFailure');
    }

    public function getValidationMessages(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationMessages');
    }

    public function getValidationRedirect(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidationRedirect');
    }

    public function getValidator(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-getValidator');
    }

    public function prepareForValidation(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-prepareForValidation');
    }

    public function rules(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-rules');
    }

    public function withValidator(ActionRequest $request): mixed
    {
        return $request->header('X-Hook-withValidator');
    }

    public function unhooked(ActionRequest $request): mixed
    {
        return $request->header('X-Unhooked');
    }

    /** @return array<string, int> */
    public function handle(): array
    {
        return ['id' => 1];
    }

    /** @return array<string, int> */
    public function store(): array
    {
        return ['id' => 1];
    }
}
