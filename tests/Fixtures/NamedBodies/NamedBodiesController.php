<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NamedBodies;

use Docuccino\Attributes\Response;

/**
 * Sign-in steps answering 422 with either a validation problem or a next-step challenge, the challenge a
 * union no class stands for. Two steps name the union alike; one names it with a key no component can
 * carry. Never dispatched.
 */
final class NamedBodiesController
{
    #[Response(status: 422, type: 'array{challenge_type: string, code_length: int}|array{challenge_type: string, provider: string}', schemaName: 'SignInChallenge')]
    public function verifyCode(): array
    {
        return [];
    }

    #[Response(status: 422, type: 'array{challenge_type: string, code_length: int}|array{challenge_type: string, provider: string}', schemaName: 'SignInChallenge')]
    public function verifyLink(): array
    {
        return [];
    }

    #[Response(status: 422, type: 'array{challenge_type: string, email: string}', schemaName: 'Sign In/Challenge')]
    public function verifyEmail(): array
    {
        return [];
    }
}
