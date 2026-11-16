<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Why a token was refused, each case mapping to the status a refusal for it answers with.
 */
enum TokenRefusal: string
{
    case Expired = 'token_expired';
    case Revoked = 'token_revoked';
    case AlreadyRotated = 'already_rotated';

    public function status(): int
    {
        return match ($this) {
            self::Expired, self::Revoked => 401,
            self::AlreadyRotated => 409,
        };
    }

    public function httpStatus(): int
    {
        if ($this === self::AlreadyRotated) {
            return 409;
        }

        return 401;
    }
}
