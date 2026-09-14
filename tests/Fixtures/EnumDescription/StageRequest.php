<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumDescription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A FormRequest reaching two enum components through validation rules alone — the producer that never
 * goes near the type chain. Only ever reflected.
 */
final class StageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage' => [Rule::enum(ReviewStage::class)],
            'hold' => [Rule::enum(UnreadableStage::class)],
        ];
    }
}
