<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workbench\App\Enums\WidgetStatus;

/**
 * A FormRequest on a READ verb validating an enum-constrained query key, so an out-of-enum value is a
 * 422 the query parameter's own schema describes.
 */
final class ListWidgetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(WidgetStatus::class)],
        ];
    }
}
