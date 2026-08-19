<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The one way the adapter says "this endpoint answers with server-sent events": `text/event-stream`
 * carrying a `string`.
 *
 * The schema is deliberately the WIRE FORMAT and not the event payload. An SSE body is a sequence of
 * `event:`/`data:` frames that never ends until the connection does, and an OpenAPI response body is a
 * single value — so a schema naming the yielded object would tell a consumer the body IS one event,
 * which is false for every stream that sends two. What the generator yields is a fact about one frame,
 * and there is nowhere in a 3.1 response object to say it without saying more than that.
 */
final class EventStreamRepresentation
{
    public const MEDIA_TYPE = 'text/event-stream';

    /** @var array<string, mixed> */
    public const SCHEMA = ['type' => 'string'];
}
