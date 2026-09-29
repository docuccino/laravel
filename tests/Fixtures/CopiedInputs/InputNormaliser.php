<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

/** Application code a FormRequest hands itself, or one of its bags, to — each of which writes the input. */
final class InputNormaliser
{
    public function __construct(private readonly ?Request $request = null) {}

    public static function apply(Request $request): void
    {
        $request->merge(['key' => 'overwritten']);
    }

    public static function trim(ParameterBag $bag): void
    {
        $bag->set('key', 'overwritten');
    }

    public function fill(Request $request): void
    {
        $request->merge(['key' => 'overwritten']);
    }

    public function run(): void
    {
        $this->request?->merge(['key' => 'overwritten']);
    }
}
