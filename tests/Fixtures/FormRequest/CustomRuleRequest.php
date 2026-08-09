<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\VendorCurrencyRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest whose rules mix documented rule objects with an undocumented one, so the recovery is
 * exercised on both sides of the attribute. Only ever reflected.
 */
final class CustomRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', new BankReference('GB')],
            'currency' => ['required', new VendorCurrencyRule],
            // Nothing else to fold, so this field is the unrecoverable-diagnostic case.
            'token' => new OpaqueCheck,
        ];
    }
}
