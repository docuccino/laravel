<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
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

it('prints help under the message it belongs to', function (): void {
    // The command a reader has to run only helps them where they are reading, which is here.
    $output = Console::render([Console::diagnostic(
        'The inference engine is not installed.',
        help: 'Install it where you generate: composer require --dev docuccino/inference-phpstan.',
    )]);

    expect($output)->toContain("    [warning] demo.code: The inference engine is not installed.\n"
        ."      Install it where you generate: composer require --dev docuccino/inference-phpstan.\n");
});

it('gives a diagnostic that states no help the page that documents it', function (): void {
    // Where a producer wrote no help, the reference is the help — and `demo.` maps to no section, so
    // the link lands on the page itself rather than promising an anchor that isn't there.
    $output = Console::render([Console::diagnostic('fine', 'GET api/orders')]);

    expect($output)->toBe("\nDiagnostics for default:\n  GET api/orders\n    [warning] demo.code: fine\n      https://docs.docuccino.app/laravel/reference/diagnostics/\n");
});

it('indents every line of a multi-line help, and keeps the blank line between paragraphs', function (): void {
    $output = Console::render([Console::diagnostic('fine', help: "First do this.\n\nThen do that.")]);

    expect($output)->toContain("    [warning] demo.code: fine\n      First do this.\n\n      Then do that.\n");
});

it('reads a lone carriage return as a line break rather than an escape', function (): void {
    // Windows and classic-Mac line endings are layout too; anything else in help still gets escaped.
    expect(Console::render([Console::diagnostic('fine', help: "one\r\ntwo\rthree")]))
        ->toContain("      one\n      two\n      three\n");
});

it('escapes help as readily as a message, since help quotes an exception', function (): void {
    $output = Console::render(
        [Console::diagnostic('fine', help: "run \x1B[31m<fg=red>this</>\u{202E}")],
        decorated: true,
    );

    expect($output)->toContain('\x1B[31m<fg=red>this</>\u{202E}')
        ->and($output)->not->toContain("\e[31m");
});

it('cannot be made to forge a diagnostic line from help', function (): void {
    // Help is the one value whose newlines become layout, so the indent is what keeps it help: an
    // injected line still lands deeper than the four spaces a real diagnostic sits at.
    $output = Console::render([Console::diagnostic('fine', help: "ok\n[error] fake.code: shipped")]);

    expect($output)->toContain('      [error] fake.code: shipped')
        ->and($output)->not->toContain("\n    [error] fake.code: shipped");
});

/*
 * The other half of what the console owes a reader: `diagnostics.accept` changes an exit code, so
 * every line it covers has to say so where the line is read.
 */
it('marks a diagnostic the accepted list covers, and totals them under the block', function (): void {
    config()->set('docuccino.diagnostics.accept', ['demo.code']);

    $output = Console::render([Console::diagnostic('fine', 'GET api/orders'), Console::diagnostic('also fine')]);

    expect($output)->toContain('    [warning, accepted] demo.code: fine')
        ->and($output)->toContain('  Accepted, so --fail-on ignores them: demo.code (2)');
});

it('marks nothing, and totals nothing, where the list names another code', function (): void {
    config()->set('docuccino.diagnostics.accept', ['other.code']);

    $output = Console::render([Console::diagnostic('fine', 'GET api/orders')]);

    expect($output)->toBe("\nDiagnostics for default:\n  GET api/orders\n    [warning] demo.code: fine\n      https://docs.docuccino.app/laravel/reference/diagnostics/\n");
});

it('never marks an error accepted, however the list reads', function (): void {
    config()->set('docuccino.diagnostics.accept', ['demo.code']);

    $error = new Diagnostic(severity: Severity::Error, code: 'demo.code', message: 'gone');

    expect(Console::render([$error]))->toContain('    [error] demo.code: gone')
        ->and(Console::render([$error]))->not->toContain('Accepted, so --fail-on ignores them');
});
