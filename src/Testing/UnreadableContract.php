<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use RuntimeException;

/**
 * The artifact the contract assertions read could not be read. Every message names the artisan command
 * that produces one — the reader is a developer wiring up their suite, and the fix is always a build
 * step or one line of test bootstrap.
 */
final class UnreadableContract extends RuntimeException
{
    public static function notFound(string $path, string $document): self
    {
        return new self(sprintf(
            "Docuccino could not read the contract artifact at %s.\n".
            "Generate it with: php artisan docuccino:export%s\n".
            "Or point the assertions at a different file: Docuccino\\Laravel\\Testing\\ApiContract::using('…').",
            $path,
            $document === 'default' ? '' : ' '.$document,
        ));
    }

    public static function notJson(string $path, string $detail): self
    {
        return new self(sprintf(
            "The contract artifact at %s is not a JSON document (%s).\n".
            'Regenerate it with: php artisan docuccino:export',
            $path,
            $detail,
        ));
    }

    public static function unknownDocument(string $key): self
    {
        return new self(sprintf(
            'No document "%s" is configured in config/docuccino.php.',
            $key,
        ));
    }
}
