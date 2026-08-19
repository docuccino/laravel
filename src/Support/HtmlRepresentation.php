<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The one way the adapter says "this endpoint answers with HTML": a `text/html` body whose schema is a
 * plain `string`. Markup has no structure the code proves, so `string` is the widest true answer and
 * anything narrower would be invented. Every path that documents HTML — a rendered view returned from
 * an action, a laravel-actions `htmlResponse()` — writes it through here, so the two cannot drift.
 */
final class HtmlRepresentation
{
    public const MEDIA_TYPE = 'text/html';

    /**
     * The body schema. Deliberately just a type: a rendered template is a string, and a consumer told
     * anything more would be told something we made up.
     *
     * @var array<string, mixed>
     */
    public const SCHEMA = ['type' => 'string'];
}
