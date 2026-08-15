<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames;

use Docuccino\Attributes\BodyParameter;

/**
 * The real-world collision, reached from both sides: the input `SSOConnectionData` arrives as a
 * request body and the output one leaves as a response, exactly as an app that publishes both does.
 */
final class SsoController
{
    #[BodyParameter(name: 'connection', type: 'Docuccino\Laravel\Tests\Fixtures\ComponentNames\Schema\Authentication\SSOConnectionData', required: true)]
    public function store(): array
    {
        return [];
    }

    public function show(): array
    {
        return [];
    }

    public function legacy(): array
    {
        return [];
    }

    /** An unrelated endpoint, added later, whose URI sorts before every route above. */
    public function unrelated(): array
    {
        return [];
    }
}
