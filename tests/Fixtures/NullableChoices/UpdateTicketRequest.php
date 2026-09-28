<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NullableChoices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A body whose closed-set fields may also be sent as null, beside one that may not. */
final class UpdateTicketRequest extends FormRequest
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
            'status' => ['nullable', 'in:open,closed'],
            'priority' => ['nullable', Rule::in(['low', 'high'])],
            'channel' => ['required', 'in:email,phone'],
        ];
    }
}
