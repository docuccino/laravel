<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;

/**
 * A NEW producer written the careless way: it states a name it read straight into a diagnostic and puts
 * it through nothing. That is the code the invariant has to refuse, so a row can hold the published
 * document to being safe whichever producer built the diagnostic — no allow-list of the ones that
 * remembered.
 *
 * The text is deliberately every hazard at once: an ANSI sequence, a C1 introducer, a direction
 * override, a line separator and a bare newline, in the code, the message and the help alike.
 */
final class RawTextDiagnosticExtension implements OperationExtension
{
    /**
     * The route this producer is exercised on. Hostile too, and deliberately: `routeSignature` is the
     * one field a diagnostic publishes unescaped, so a tame path would leave that exemption untested.
     */
    public const string HOSTILE_PATH = "api/zz-raw\x1B[31m\u{009B}31m\u{202E}\u{2028}text";

    /** The code as this producer states it — before anything neutralises it. */
    public const string RAW_CODE = "test.raw\u{202E}edoc";

    /** A name "read out of the application", carrying everything that steers a terminal. */
    public const string RAW_NAME = "Evil\x1B[31m\u{009B}31m\u{202E}\u{2028}\r\nName";

    /**
     * The same name without its line break, for `help`. A break there is layout rather than a hazard,
     * so a name carrying one adds an indented line instead of being escaped — true, and a different
     * fact from the one these rows are about.
     */
    public const string RAW_NAME_ONE_LINE = "Evil\x1B[31m\u{009B}31m\u{202E}\u{2028}Name";

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: self::RAW_CODE,
            message: sprintf('The name "%s" was read as written.', self::RAW_NAME),
            routeSignature: $context->route->signature($context->httpMethod()),
            help: sprintf("Correct it.\nIt is spelled \"%s\" today.", self::RAW_NAME_ONE_LINE),
        ));
    }
}
