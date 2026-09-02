<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use RuntimeException;

/**
 * A batch the service refused. Where it came from is a property of the failure rather than of the
 * endpoint, so no render arm can write it out.
 */
final class BatchRefusedException extends RuntimeException
{
    public function __construct(private readonly BatchOrigin&BatchVerified $origin)
    {
        parent::__construct('The batch was refused.');
    }

    public function origin(): BatchOrigin&BatchVerified
    {
        return $this->origin;
    }
}
