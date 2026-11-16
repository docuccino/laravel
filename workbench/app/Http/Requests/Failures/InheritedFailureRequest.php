<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

/** Inherits how its base answers a failed validation. */
final class InheritedFailureRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string']];
    }
}
