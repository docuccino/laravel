<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\UploadRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

/**
 * Uploads bounded by the framework's dimension and file builders: dimensions given as a keyed array to `new`
 * and to `Rule::dimensions()`, set one by one, and handed to an image file rule, beside a file rule's types
 * and size.
 */
final class StoreMediaRequest extends FormRequest
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
            'avatar' => ['required', 'image', new Dimensions(['min_width' => 100, 'min_height' => 100])],
            'banner' => ['sometimes', 'image', Rule::dimensions(['max_width' => 1920, 'max_height' => 1080])],
            'icon' => ['sometimes', 'image', Rule::dimensions()->minWidth(64)->maxWidth(512)],
            'photo' => ['sometimes', File::image()->dimensions(Rule::dimensions()->maxWidth(4000))],
            'document' => ['sometimes', File::types(['pdf', 'docx'])->max('10mb')],
        ];
    }
}
