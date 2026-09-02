<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/**
 * The credentials the request presented were rejected. Both the problem type and the sentence shown to
 * the caller depend on the scheme they used, so each is assembled at run time rather than written out.
 */
final class CredentialsRejectedException extends RuntimeException
{
    public function __construct(private readonly string $scheme)
    {
        parent::__construct('The '.$scheme.' credentials were rejected.');
    }

    /** The problem type for the scheme this failure came from — a value no fold can read. */
    public function problemType(): string
    {
        return 'https://example.com/problems/'.$this->scheme.'-rejected';
    }
}
