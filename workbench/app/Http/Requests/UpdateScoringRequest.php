<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest whose nested body keys are named the only way Laravel's rule vocabulary names them —
 * with dots — including a bare `array` parent whose container the rules leave undecided.
 */
final class UpdateScoringRequest extends FormRequest
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
            'is_required' => 'sometimes|boolean',
            'meta' => 'sometimes|nullable|array',
            'meta.validation_overrides' => 'sometimes|nullable|array',
            'meta.scoring.scores' => 'sometimes|array',
        ];
    }
}
