<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ArrayItemRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * A body whose lists are validated item by item through `field.*` keys: closed sets and excluded values
 * written as rule objects and as `Rule::` factories, and `distinct` at each depth a `*` can put it.
 */
final class IssueTokenRequest extends FormRequest
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
            'abilities' => ['required', 'array'],
            'abilities.*' => ['distinct', new Enum(TokenAbility::class)],
            'roles' => ['array'],
            'roles.*' => ['distinct:strict', Rule::enum(TokenAbility::class)],
            'scopes' => ['array'],
            'scopes.*' => ['string', Rule::in(['profile', 'email'])],
            'channels' => ['array'],
            'channels.*' => ['string', new In(['email', 'sms'])],
            'default_ability' => [new Enum(TokenAbility::class)],
            'labels' => ['array'],
            'labels.*' => ['string', 'distinct:ignore_case', 'max:20'],
            'aliases' => ['array'],
            'aliases.*' => ['nullable', 'string', 'distinct'],
            'tags' => ['array', 'distinct'],
            'tags.*' => ['string'],
            'grants' => ['array'],
            'grants.*.ability' => ['required', 'distinct', new Enum(TokenAbility::class)],
            'grants.*.note' => ['string'],
            'matrix' => ['array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['integer', 'distinct'],
            'handles' => ['array'],
            'handles.*' => ['string', Rule::notIn(['root', 'admin'])],
            'nicknames' => ['array'],
            'nicknames.*' => ['string', new NotIn(['root', 'admin'])],
            'settings' => [Rule::array(['theme'])],
        ];
    }
}
