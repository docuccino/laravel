<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\Concerns\AsAction;

/** Takes its hook from a trait. */
final class TraitHookAction
{
    use AsAction;
    use CopiesKeyInAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function handle(): void {}
}
