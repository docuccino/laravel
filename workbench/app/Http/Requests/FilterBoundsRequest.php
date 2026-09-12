<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest that validates one of the query string's FILTER keys — the ordinary way an
 * application bounds a filter its own code applies, and a producer that types the parameter a phase
 * after the Query Builder integration wrote it.
 */
final class FilterBoundsRequest extends FormRequest
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
            'filter.min_days' => 'integer|min:1|max:90',
        ];
    }
}
