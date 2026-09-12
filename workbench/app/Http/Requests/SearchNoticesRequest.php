<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same unreadable rules on a READ verb, where they become query parameters — so the declaration that
 * answers for one of them is the action's `#[QueryParameter]`, and the half of the document a note sends
 * the reader to is not a request body. Only ever reflected; its trace is scripted where the document is
 * built.
 */
final class SearchNoticesRequest extends FormRequest
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
            'marker' => [static fn (): bool => true],
            'secret' => [static fn (): bool => true],
            'scope' => ['array'],
            'window' => ['array'],
        ];
    }
}
