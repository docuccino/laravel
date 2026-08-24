<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Applies `#[IgnoreParam]`. It is the only subtractive parameter pass, so it runs in Finalize, after
 * every producer that could write a parameter: the parameter phase's own extensions, the request phase's
 * validation recovery, and the parameter attributes. Removing a node before its producer runs removes
 * nothing, because the producer creates it again.
 *
 * It sits ahead of the example pass inside Finalize, so an `#[Example(parameter: …)]` naming something
 * this dropped reports a missing target rather than illustrating a parameter the document no longer has.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class IgnoredParametersExtension implements OperationExtension
{
    /**
     * The OAS parameter locations — where a declaration naming none of them applies, and the set the
     * diagnostic quotes. Alphabetical, because a legal set a reader checks against is easier to read in
     * an order they can predict than in the one OAS happens to list.
     */
    private const array LOCATIONS = ['cookie', 'header', 'path', 'query'];

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            foreach ($this->locations($context, $ignore) as $location) {
                $operation->removeParameter($location, $ignore->name);
            }
        }
    }

    /**
     * The locations one declaration names: all four when it names none, and the one it names otherwise,
     * matched case-insensitively — `in: 'Query'` says exactly what `in: 'query'` says, and a spelling the
     * tool can understand is not worth making the author look up.
     *
     * A value that names no location at all is the other thing: it dropped nothing, and it cannot be read
     * as any of four words, so it is reported rather than guessed at.
     *
     * @return list<string>
     */
    private function locations(RouteContext $context, IgnoreParam $ignore): array
    {
        if ($ignore->in === null) {
            return self::LOCATIONS;
        }

        $normalized = strtolower(trim($ignore->in));
        if (in_array($normalized, self::LOCATIONS, true)) {
            return [$normalized];
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.ignore-param-location',
            message: sprintf(
                '#[IgnoreParam(name: "%s", in: "%s")] names no parameter location, so nothing was dropped.',
                $ignore->name,
                $ignore->in,
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: 'A parameter is in cookie, header, path or query — spelled in any case. Leave `in:` off to drop the name from every location.',
        ));

        return [];
    }
}
