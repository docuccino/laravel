<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\PlainText;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Makes a string safe to hand to a console writer. Two hazards, two halves: {@see PlainText} covers what
 * steers a terminal directly, and the formatter escape over it covers Symfony's own markup, which
 * `line()` and `write()` would otherwise INTERPRET — `<fg=red>` recolours the operator's terminal and
 * `<info>` vanishes from the report. The formatter undoes the escape as it writes, so a legitimate
 * `array<int, string>` still reads exactly as written.
 *
 * Order matters where both halves apply: {@see PlainText} first, so the NUL the formatter's own
 * trailing-backslash escape inserts is consumed as it writes rather than escaped into view.
 *
 * Escaping belongs here, at the render boundary, rather than at the producer: the same value goes to JSON
 * and document outputs too, where `json_encode` escapes already and a second pass only garbles it.
 *
 * @internal
 */
final class TerminalText
{
    /**
     * A string lifted out of an application or an artifact, as a terminal may show it.
     */
    public static function of(string $value): string
    {
        return OutputFormatter::escape(PlainText::of($value));
    }

    /**
     * A whole report a core renderer already put through {@see PlainText} — only the markup half is still
     * owed. {@see PlainText} is idempotent, so a second pass would not hurt the values; it would escape the
     * report's OWN newlines and flatten the layout onto one line.
     */
    public static function markupOnly(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
