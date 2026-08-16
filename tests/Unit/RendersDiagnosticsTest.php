<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Support\DiagnosticConsole as Console;

/**
 * The console is the one boundary a diagnostic is escaped at, so this is where the escaping is proved.
 * Everything interpolated into these lines — a message, a route signature, a document key — was written
 * by the application being documented and validated by nothing, and the reader is an operator or whoever
 * opens the CI log afterwards, who cannot tell a forged line from a real one.
 */
it('escapes what steers a terminal wherever a message carries it', function (string $case, string $raw, string $shown): void {
    expect(Console::render([Console::diagnostic('read '.$raw.' this')]))->toContain('read '.$shown.' this');
})->with([
    // The ASCII half is what the two producer-side helpers used to escape for themselves.
    ['an escape sequence', "\x1B[31m", '\x1B[31m'],
    ['a newline that would forge a second diagnostic line', "\n", '\x0A'],
    // The rest is what only PlainText covers, and what those helpers had left reachable.
    ['a C1 control sequence introducer', "\u{009B}31m", '\u{009B}31m'],
    ['a right-to-left override', "\u{202E}", '\u{202E}'],
    ['a right-to-left isolate', "\u{2067}", '\u{2067}'],
]);

it('prints Symfony formatter markup rather than obeying it', function (): void {
    // `line()` formats what it is handed, so markup in a message is a second way to recolour a terminal,
    // and one escaping the control characters alone leaves open.
    $message = 'the field <fg=red;bg=white>is</> gone';

    expect(Console::render([Console::diagnostic($message)]))->toContain($message)
        ->and(Console::render([Console::diagnostic($message)], decorated: true))->toContain($message)
        // Nothing the message asked for reaches the terminal as colour.
        ->and(Console::render([Console::diagnostic($message)], decorated: true))->not->toContain("\e[31m");
});

it('leaves a raw escape sequence unable to colour a decorated terminal', function (): void {
    $output = Console::render([Console::diagnostic("red \x1B[31m now")], decorated: true);

    expect($output)->toContain('\x1B[31m')
        ->and($output)->not->toContain("\e[31m");
});

it('leaves a legitimate angle bracket exactly as the producer wrote it', function (): void {
    // The formatter undoes the markup escaping as it writes, so a generic type in a message is not the
    // price of closing that hole.
    $message = 'returns array<int, string>, not iterable<int, string>';

    expect(Console::render([Console::diagnostic($message)]))->toContain($message);
});

it('escapes the group heading and the document key too, not only the message', function (): void {
    $output = Console::render(
        [Console::diagnostic('fine', "GET api/orders\u{202E}gnp.exe")],
        document: "default\x1B[2J",
    );

    expect($output)->toContain('GET api/orders\u{202E}gnp.exe')
        ->and($output)->toContain('default\x1B[2J')
        ->and($output)->not->toContain("\u{202E}")
        ->and($output)->not->toContain("\x1B[2J");
});

it('escapes a code as readily as a message, since an extension states both', function (): void {
    expect(Console::render([Console::diagnostic('fine', code: "sneaky\u{202E}edoc")]))
        ->toContain('sneaky\u{202E}edoc');
});
