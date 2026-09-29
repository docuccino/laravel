<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Adds the route's key to everything it hands out — the input the default validator reads included. */
final class OwnAllRequest extends FormRequest
{
    public function all($keys = null): array
    {
        return array_merge(parent::all($keys), ['key' => $this->route('key')]);
    }

    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
