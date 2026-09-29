<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A FormRequest that copies request values into its input before validating them: headers (one a proxy
 * sets), a query value and a route parameter, each read by a literal name, beside a value it computes.
 */
final class SubmitNoteRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'note' => 'required|string',
            'title' => 'required|string',
            'idempotency_key' => 'required|uuid',
            'trace_id' => 'nullable|string|max:64',
            'channel' => 'sometimes|in:web,mobile',
            'page_size' => 'nullable|integer|min:1|max:100',
            'note_id' => 'required|integer',
            'slug' => 'required|string',
            'client_ip' => 'required|ip',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            'trace_id' => $this->headers->get('X-Trace-Id'),
            'channel' => $this->header('X-Channel'),
            'page_size' => $this->query('per_page'),
            'note_id' => $this->route('note'),
            'slug' => Str::slug((string) $this->input('title')),
            'client_ip' => $this->header('X-Forwarded-For'),
        ]);
    }
}
