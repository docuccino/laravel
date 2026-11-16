<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\AcceptedIf;
use Illuminate\Validation\Rules\RequiredWith;

/**
 * Rules that hold while another field is sent, missing or set to a value, written as the `Rule::` factories
 * and `new` objects that build them, beside rule objects of other kinds built the same way.
 */
final class StoreApplicationRequest extends FormRequest
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
            'newsletter' => ['sometimes', 'boolean'],
            'terms' => [Rule::acceptedIf('kind', 'business')],
            'contract' => [new AcceptedIf('kind', ['business', 'charity'])],
            'marketing' => [Rule::declinedIf('kind', 'business')],
            'referral' => ['string', Rule::missingIf('kind', 'business')],
            'vat' => ['string', Rule::presentIf('kind', 'business')],
            'company' => ['string', Rule::requiredWith('vat')],
            'office' => ['string', new RequiredWith(['vat', 'company'])],
            'email' => ['string', Rule::requiredIfAccepted('newsletter')],
            'nickname' => ['string', Rule::prohibits('alias')],
            'alias' => ['string'],
            'zone' => [Rule::timezone()],
            'website' => [Rule::url()->protocols(['https'])],
            'reference' => [Rule::uuid()],
        ];
    }
}
