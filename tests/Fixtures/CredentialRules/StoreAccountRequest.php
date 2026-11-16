<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CredentialRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Credentials checked by the framework's password and email builders, written with `new`, a static of the
 * rule's own class and a `Rule::` factory, beside a password checked by the application's registered default.
 */
final class StoreAccountRequest extends FormRequest
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
            'email' => ['required', Rule::email()->rfcCompliant()],
            'password' => ['required', new Password(8)],
            'new_password' => ['required', Password::min(10)->max(64)->letters()->mixedCase()],
            'recovery_password' => ['sometimes', Password::defaults()],
        ];
    }
}
