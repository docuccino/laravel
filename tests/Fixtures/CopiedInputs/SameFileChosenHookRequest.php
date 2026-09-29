<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Two traits written in one file offer a hook under one name, so only `insteadof` says which body runs. */
trait CopiesChannelHere
{
    protected function prepareForValidation(): void
    {
        $this->merge(['channel' => $this->header('X-Channel')]);
    }
}

trait CopiesDeviceHere
{
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge(['device' => $this->header('X-Device')]);
    }
}

final class SameFileChosenHookRequest extends FormRequest
{
    use CopiesChannelHere, CopiesDeviceHere {
        CopiesDeviceHere::prepareForValidation insteadof CopiesChannelHere;
    }
}
