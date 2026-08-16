<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Commands\RendersDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A command that does nothing but render diagnostics, so the escaping can be read off the bytes a real
 * Symfony formatter emits rather than off the string handed to `line()` — the formatter is half of what
 * is being tested.
 */
final class DiagnosticConsole extends Command
{
    use RendersDiagnostics;

    /**
     * What the terminal receives. `$decorated` asks for the ANSI form, which is the only one that shows
     * whether markup in a message was interpreted or printed.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    public static function render(array $diagnostics, string $document = 'default', bool $decorated = false): string
    {
        $buffer = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, $decorated);

        $command = new self;
        $command->output = new OutputStyle(new ArrayInput([]), $buffer);
        $command->renderDiagnostics($document, $diagnostics);

        return $buffer->fetch();
    }

    /** A warning carrying whatever a test needs to see printed. */
    public static function diagnostic(string $message, ?string $routeSignature = null, string $code = 'demo.code'): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: $code,
            message: $message,
            routeSignature: $routeSignature,
        );
    }
}
