<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Copies a header into its input only when the header was sent, so the body field it validates is still
 * the one a client without the header fills in.
 */
final class TenantNoteRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['tenant' => 'required|string'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasHeader('X-Tenant')) {
            $this->merge(['tenant' => $this->header('X-Tenant')]);
        }
    }
}
