<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Copies whose key something else also writes: a second merge, a fill-if-missing, a branch, a closure. */
final class RepeatedCopiesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['twice' => $this->header('X-First'), 'kept' => $this->header('X-Kept')]);
        $this->merge(['twice' => $this->header('X-Second')]);
        $this->merge(['filled' => $this->header('X-Filled')]);
        $this->mergeIfMissing(['filled' => 'fallback']);

        if ($this->hasHeader('X-Branch')) {
            $this->merge(['branch' => $this->header('X-Branch')]);
        }

        $this->merge(['closure' => $this->header('X-Closure')]);
        collect([1])->each(fn () => $this->merge(['closure' => 'late']));
    }
}
