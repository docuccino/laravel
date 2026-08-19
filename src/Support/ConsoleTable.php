<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * A plain column layout: a dim header, one `─` rule under it, and rows aligned on the widest cell.
 * Symfony's table draws `+----+` box art, which reads as a different tool beside the `─` rules the
 * rest of the console output uses.
 *
 * Widths come from the content and never from the terminal, so the same data renders the same bytes
 * on every machine — the same reason nothing here uses a dot leader.
 *
 * @internal
 */
final class ConsoleTable
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows  values as written by the application; escaped here
     * @return list<string>
     */
    public static function render(array $headers, array $rows): array
    {
        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($cell));
            }
        }

        $lines = [
            '  <fg=gray>'.self::row($headers, $widths, escape: false).'</>',
            '  <fg=gray>'.self::row(array_values(array_map(static fn (int $width): string => str_repeat('─', $width), $widths)), $widths, escape: false).'</>',
        ];

        foreach ($rows as $row) {
            $lines[] = '  '.self::row($row, $widths, escape: true);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, int>  $widths
     */
    private static function row(array $cells, array $widths, bool $escape): string
    {
        $padded = [];
        foreach ($cells as $column => $cell) {
            $pad = ($widths[$column] ?? 0) - mb_strlen($cell);
            $padded[] = ($escape ? TerminalText::of($cell) : $cell).str_repeat(' ', max(0, $pad));
        }

        return rtrim(implode('  ', $padded));
    }
}
