<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\UnmatchedDeclaration;

/*
 * The message half of the four "this declaration matched nothing" reports. The author wrote a name;
 * what makes the report a remedy rather than a complaint is the list of what there WAS to name, which
 * is where they will see their typo. These pin the things that list has to get right — a cap, an empty
 * set, a value nobody may print as it stands, and the sentence each member wraps it in.
 */

it('names what the operation documents so the author can see the difference', function (): void {
    $diagnostic = UnmatchedDeclaration::parameter(
        new IgnoreParam(name: 'trace', in: 'query'),
        ['header:X-Trace', 'query:trace_id'],
        null,
        'GET api/forms',
    );

    expect($diagnostic->code)->toBe('attribute.ignore-param-unmatched')
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        // The declaration as the author wrote it, both arguments.
        ->and($diagnostic->message)->toContain('#[IgnoreParam(name: "trace", in: "query")]')
        ->and($diagnostic->message)->toContain('It documents header:X-Trace, query:trace_id.')
        ->and($diagnostic->routeSignature)->toBe('GET api/forms');
});

it('leaves `in:` out of the declaration it quotes when the author did', function (): void {
    expect(UnmatchedDeclaration::parameter(new IgnoreParam(name: 'trace'), [], null, null)->message)
        ->toContain('#[IgnoreParam(name: "trace")]')
        ->not->toContain('in:');
});

it('says an operation documents nothing rather than naming an empty list', function (string $kind, string $expected): void {
    $message = $kind === 'parameter'
        ? UnmatchedDeclaration::parameter(new IgnoreParam(name: 'trace'), [], null, null)->message
        : UnmatchedDeclaration::response(419, [], null, null)->message;

    expect($message)->toContain($expected);
})->with([
    // An operation with no parameters at all is ordinary; "It documents ." would read as a bug in us.
    'parameter' => ['parameter', 'It documents no parameters at all.'],
    'response' => ['response', 'It documents no responses at all.'],
]);

it('caps the list it names and says how many it did not', function (): void {
    $statuses = ['200', '201', '202', '400', '401', '403', '404', '409', '422', '429'];

    $message = UnmatchedDeclaration::response(419, $statuses, null, null)->message;

    expect($message)->toContain('It documents 200, 201, 202, 400, 401, 403, 404, 409 and 2 more.')
        // Anti-vacuity: the row that was cut really is one of the inputs, so a cap that stopped
        // cutting would fail here rather than agree with any list at all.
        ->and($statuses)->toContain('429')
        ->and($message)->not->toContain('429');
});

it('escapes a name it did not write', function (): void {
    // A parameter name is recovered from an application's own code — a validation rule key, a query
    // string it composes — so it reaches this message unread, and an escape sequence in one steers the
    // terminal it is printed to.
    $diagnostic = UnmatchedDeclaration::parameter(
        new IgnoreParam(name: "trace\x1b[31m"),
        ["query:sort\x07"],
        null,
        null,
    );

    expect($diagnostic->message)->not->toContain("\x1b")
        ->and($diagnostic->message)->not->toContain("\x07")
        ->and($diagnostic->message)->toContain('\x1B')
        ->and($diagnostic->message)->toContain('\x07');
});

it('quotes the status the author wrote, twice, and reads it back the same', function (): void {
    $diagnostic = UnmatchedDeclaration::response(419, ['200', '404'], null, 'GET api/forms');

    expect($diagnostic->code)->toBe('attribute.ignore-response-unmatched')
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->message)->toContain('#[IgnoreResponse(status: 419)]')
        // The consequence names the status too: what the reader scans for is the number.
        ->and($diagnostic->message)->toContain('no producer would have written a 419 response')
        ->and($diagnostic->message)->toContain('It documents 200, 404.');
});

it('says no documents are configured rather than naming an empty list', function (): void {
    // Reachable: `documents` can be configured empty, and a message that trailed off after "The
    // configured documents are ." would read as a bug in us rather than as the configuration problem
    // it is.
    expect(UnmatchedDeclaration::document('admn', ['GET api/things'], [], stranded: true)->message)
        ->toContain('No documents are configured at all.');
});

it('gives each member the sentence its own kind of name belongs in', function (): void {
    // One list renderer, four sentences: a sentence that fitted all of them would be true of none, and
    // the cap and the escaping are the only halves that are policy.
    $parameter = UnmatchedDeclaration::parameter(new IgnoreParam(name: 'trace'), ['query:sort'], null, null)->message;
    $document = UnmatchedDeclaration::document('admn', ['GET api/a'], ['default'], stranded: false)->message;
    $path = UnmatchedDeclaration::pathParameter('postId', ['post'], null, null)->message;

    expect($parameter)->toContain('It documents query:sort.')
        ->and($document)->toContain('The configured documents are default.')
        ->and($path)->toContain('It has {post}.');
});
