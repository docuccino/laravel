<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\Concerns\AsAction;

/** Takes an attributed hook from a trait. */
final class AttributedTraitHookAction
{
    use AsAction;
    use CopiesKeyAttributedInAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function handle(): void {}
}
