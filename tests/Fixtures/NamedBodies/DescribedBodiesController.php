<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NamedBodies;

use Docuccino\Attributes\Response;

/**
 * Sign-in steps naming their challenge bodies and saying what each is: the next-step challenge described on
 * one step and named bare on another; a second-factor challenge described alike on two; a recovery challenge two
 * steps describe differently; and one step describing a body it never names. Never dispatched.
 */
final class DescribedBodiesController
{
    #[Response(status: 422, type: 'array{challenge_type: string, code_length: int}', schemaName: 'SignInChallenge', schemaDescription: 'Sign-in did not complete: one further step is required.')]
    public function verifyCode(): array
    {
        return [];
    }

    #[Response(status: 422, type: 'array{challenge_type: string, code_length: int}', schemaName: 'SignInChallenge')]
    public function verifyLink(): array
    {
        return [];
    }

    #[Response(status: 202, type: 'array{token: string}', schemaName: 'SecondFactorChallenge', schemaDescription: 'The code was accepted, and a second factor is needed as well.')]
    public function secondFactor(): array
    {
        return [];
    }

    #[Response(status: 202, type: 'array{token: string}', schemaName: 'SecondFactorChallenge', schemaDescription: 'The code was accepted, and a second factor is needed as well.')]
    public function secondFactorAgain(): array
    {
        return [];
    }

    #[Response(status: 409, type: 'array{recovery_code_length: int}', schemaName: 'RecoveryChallenge', schemaDescription: 'Enter a recovery code.')]
    public function recover(): array
    {
        return [];
    }

    #[Response(status: 409, type: 'array{recovery_code_length: int}', schemaName: 'RecoveryChallenge', schemaDescription: 'A recovery code is required to continue.')]
    public function recoverByEmail(): array
    {
        return [];
    }

    #[Response(status: 422, type: 'array{challenge_type: string, email: string}', schemaDescription: 'An email challenge.')]
    public function verifyEmail(): array
    {
        return [];
    }
}
