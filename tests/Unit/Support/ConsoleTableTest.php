<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\ConsoleTable;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Widths come from the content, never from the terminal, so the same rows render the same bytes on
 * every machine — the same reason nothing in this tool uses a dot leader.
 */
it('aligns on the widest cell and rules the header the way the rest of the tool does', function (): void {
    $lines = (new OutputFormatter(false))->formatAndWrap(implode("\n", ConsoleTable::render(
        ['Method', 'URI'],
        [['GET', '/api/invoices'], ['DELETE', '/api/x']],
    )), 0);

    expect($lines)->toBe(implode("\n", [
        '  Method  URI',
        '  ──────  ─────────────',
        '  GET     /api/invoices',
        '  DELETE  /api/x',
    ]))
        // No box art: the header rule is the same character the report's own rules use.
        ->and($lines)->not->toContain('+')
        ->and($lines)->not->toContain('|');
});

it('never lets a cell an application wrote steer the terminal', function (): void {
    $rendered = (new OutputFormatter(true))->format(implode("\n", ConsoleTable::render(['Route'], [["red \x1B[31m <fg=red>x</>"]])));

    expect($rendered)->toContain('\x1B[31m')
        ->and($rendered)->toContain('<fg=red>x</>')
        ->and($rendered)->not->toContain("\e[31m");
});

it('renders a header on its own when there are no rows', function (): void {
    expect(ConsoleTable::render(['Field'], []))->toBe([
        '  <fg=gray>Field</>',
        '  <fg=gray>─────</>',
    ]);
});
