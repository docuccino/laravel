<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

/** Declares no gate of its own and is gated all the same, by {@see BaseGateRequest}. */
final class InheritedGateRequest extends BaseGateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
