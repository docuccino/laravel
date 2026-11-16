<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalPresence;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\RequiredIf;

/**
 * Required and prohibited rules that hold on a condition: a literal one, a closure, and a value only the
 * request decides, written as `new` objects, as `Rule::` factories and as the cross-field string forms.
 */
final class StoreProfileRequest extends FormRequest
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
            'name' => ['required', 'string'],
            'kind' => ['sometimes', 'string'],
            'email' => [new RequiredIf(true), 'string'],
            'phone' => [new RequiredIf(false), 'string'],
            'city' => [Rule::requiredIf(true), 'string'],
            'country' => [Rule::requiredIf(false), 'string'],
            'street' => [new RequiredIf(fn (): bool => true), 'string'],
            'postcode' => [Rule::requiredIf(fn (): bool => false), 'string'],
            'company' => [Rule::requiredIf($this->input('kind') === 'business'), 'string'],
            'vat' => ['required_if:kind,business', 'string'],
            'legacy_id' => [new ProhibitedIf(true)],
            'nickname' => [new ProhibitedIf(false), 'string'],
            'alias' => [Rule::prohibitedIf(true)],
            'handle' => [Rule::prohibitedIf(false), 'string'],
            'avatar' => [new ProhibitedIf(fn (): bool => true), 'string'],
            'banner' => [Rule::prohibitedIf(fn (): bool => false), 'string'],
            'discount' => [Rule::prohibitedIf($this->input('kind') === 'business'), 'string'],
            'referrer' => ['prohibited_if:kind,business', 'string'],
        ];
    }
}
