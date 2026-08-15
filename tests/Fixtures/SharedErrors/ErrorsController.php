<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Docuccino\Attributes\Response;

/**
 * Two genuinely different `403` bodies, plus two routes that state one of them with different prose.
 * That is the real shape of the problem: a status carries one shape far more often than two, and what
 * varies between operations is almost always how they describe it rather than what they return.
 */
final class ErrorsController
{
    #[Response(status: 403, type: 'array{code: string}', description: 'Forbidden')]
    public function denied(): array
    {
        return [];
    }

    /** The same body as {@see denied()}, described differently — presentation, not contract. */
    #[Response(status: 403, type: 'array{code: string}', description: 'You may not do that')]
    public function deniedAgain(): array
    {
        return [];
    }

    #[Response(status: 403, type: 'array{detail: string}', description: 'Forbidden')]
    public function blocked(): array
    {
        return [];
    }

    #[Response(status: 403, type: 'array{detail: string}', description: 'Forbidden')]
    public function blockedAgain(): array
    {
        return [];
    }

    /** An unrelated endpoint, added later, whose URI sorts before every route above. */
    #[Response(status: 403, type: 'array{code: string}', description: 'Forbidden')]
    public function unrelated(): array
    {
        return [];
    }

    /**
     * A third shape on the same status, so a status carrying two is not the only case tested. Its
     * property sorts before the other two, so a positional name would renumber them both.
     */
    #[Response(status: 403, type: 'array{alert: string}', description: 'Refused')]
    public function refused(): array
    {
        return [];
    }

    /** …and its second occurrence, which is what takes the third shape over the sharing threshold. */
    #[Response(status: 403, type: 'array{alert: string}', description: 'Refused')]
    public function refusedAgain(): array
    {
        return [];
    }
}
