<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fields that must be accepted or declined only while another field holds a value, beside the
 * unconditional forms.
 */
final class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string'],
            'privacy' => ['accepted'],
            'terms' => ['accepted_if:kind,business'],
            'contract' => ['accepted_if:kind,business,charity'],
            'newsletter' => ['boolean', 'accepted_if:kind,business'],
            'marketing' => ['declined_if:kind,business'],
            'tracking' => ['nullable', 'declined_if:kind,business'],
        ];
    }
}
