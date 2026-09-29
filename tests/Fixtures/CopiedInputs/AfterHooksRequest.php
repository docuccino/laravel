<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Adds checks to the validator it was built with; neither hook changes what the rules are run over. */
final class AfterHooksRequest extends FormRequest
{
    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->header('Idempotency-Key') === 'reused') {
                    $validator->errors()->add('key', 'The key has been used.');
                }
            },
        ];
    }

    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('key', 'max:36', fn (): bool => true);
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->isMethod('GET')) {
                $validator->errors()->add('key', 'Not accepted on a read.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
