<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RuleBuilders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\StringRule;

/**
 * Rules built fluently from the framework's string, date, number and list builders, as `Rule::` factories
 * and as `new` objects, beside one whose bound comes from the request.
 */
final class StoreOrderRequest extends FormRequest
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
            'name' => ['required', Rule::string()->min(2)->max(40)],
            'code' => ['required', (new StringRule)->alphaDash()],
            'delivery' => ['required', Rule::date()->format('Y-m-d')->afterToday()],
            'quantity' => ['required', Rule::numeric()->integer()->min(1)->max(99)],
            'tags' => ['required', 'array', Rule::contains(['featured'])],
            'labels' => ['sometimes', 'array', Rule::doesntContain(['spam'])],
            'note' => ['sometimes', Rule::string()->max($this->noteLength())],
        ];
    }

    private function noteLength(): int
    {
        return 200;
    }
}
