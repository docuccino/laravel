<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An application error that answers with more than one status: each factory names its own, so the class
 * pins none and a throw that builds nothing states nothing either.
 */
final class LedgerRejectedException extends HttpException
{
    public static function locked(string $ledger): self
    {
        return new self(423, sprintf('Ledger %s is being posted.', $ledger));
    }

    public static function stale(string $ledger): self
    {
        return new self(409, sprintf('Ledger %s has moved on.', $ledger));
    }
}
