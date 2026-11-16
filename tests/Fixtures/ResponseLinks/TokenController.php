<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A token made and read back, by key and by code. Never dispatched.
 */
final class TokenController
{
    public function store(): Token
    {
        return Token::create([]);
    }

    public function show(Token $token): Token
    {
        return $token;
    }
}
