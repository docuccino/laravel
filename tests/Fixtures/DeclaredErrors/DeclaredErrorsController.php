<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\Response;

/**
 * Routes whose thrown exceptions the suite scripts on the stub engine. The bodies come from the error
 * tiers, so the actions themselves only need to exist and be routable.
 *
 * The last two actions carry `#[ErrorComponent]` on the ACTION — a placement `TARGET_METHOD` permits
 * and nothing reads — which is what those rows are for.
 */
final class DeclaredErrorsController
{
    public function first(): array
    {
        return [];
    }

    public function second(): array
    {
        return [];
    }

    public function third(): array
    {
        return [];
    }

    public function fourth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    public function fifth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    public function sixth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone')]
    public function seventh(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone')]
    public function eighth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'DeclaredGone')]
    public function ninth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'DeclaredGone')]
    public function tenth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'DeclaredGone')]
    #[Response(status: 410, mediaType: 'application/problem+json', errorComponent: 'SecondName')]
    public function eleventh(): array
    {
        return [];
    }

    /** Names the 409 the error tiers build, over the `#[ErrorComponent]` its thrown exception declares. */
    #[Response(status: 409, description: 'Conflict', errorComponent: 'DeclaredConflict')]
    public function twelfth(): array
    {
        return [];
    }

    /**
     * The reported topology: a 422 answered with a second representation beside the error tier's, thrown
     * from a class whose `#[ErrorComponent]` speaks for the body IT raises and cannot see the one put
     * beside it. Paired with `fourteenth` so the two-representation body repeats and publishes.
     */
    #[Response(status: 422, mediaType: 'application/problem+json', type: 'array{detail: string}')]
    public function thirteenth(): array
    {
        return [];
    }

    #[Response(status: 422, mediaType: 'application/problem+json', type: 'array{detail: string}')]
    public function fourteenth(): array
    {
        return [];
    }

    /** The same class's error answered with ONE representation, which is the body its name describes. */
    public function fifteenth(): array
    {
        return [];
    }

    public function sixteenth(): array
    {
        return [];
    }

    /** A space is the first thing anyone tries, and no `$ref` can point at what it makes. */
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'Auth Challenge')]
    public function seventeenth(): array
    {
        return [];
    }

    /** A name on a status that shares no error body — the argument is inert and has to say so. */
    #[Response(status: 200, type: 'array{ok: bool}', description: 'OK', errorComponent: 'NotAnError')]
    public function nineteenth(): array
    {
        return [];
    }

    /** The same, on the `3xx` half of the same rule. */
    #[Response(status: 302, description: 'Found', errorComponent: 'NotAnError')]
    public function twentieth(): array
    {
        return [];
    }

    /** A status a mapper turns into a `$ref`: the component it points at is named where it is defined. */
    #[Response(status: 404, errorComponent: 'NamesTheReference')]
    public function twentyFirst(): array
    {
        return [];
    }

    /** An empty name is no name: it neither publishes nor stands in the way of the one beside it. */
    #[Response(status: 410, mediaType: 'application/problem+json', errorComponent: '')]
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', errorComponent: 'RealName')]
    public function eighteenth(): array
    {
        return [];
    }
}
