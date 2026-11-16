<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest bounding the page size a list endpoint reads — the ordinary way an application
 * caps `per_page`, and a producer that bounds the parameter a phase after the pagination producer wrote it.
 */
final class PageSizeBoundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'per_page' => 'integer|min:1|max:20',
        ];
    }
}
