<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * The body {@see VersionedFormController::store()} really validates, so
 * a per-version contract check over it is a check on the wire rather than on a document written to suit
 * it. `title` is required, which is what lets an older version's document — where the same field is
 * called `name` — refuse a head-shaped request.
 */
final class StoreVersionedFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
        ];
    }
}
