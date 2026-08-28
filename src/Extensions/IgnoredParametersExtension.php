<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\UnmatchedDeclaration;

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
        // Which declarations matched is decided against the parameters standing BEFORE any removal, so
        // two that name one parameter — a controller's and the action's own, or one spelling `in:` and
        // one leaving it off — both count as having done their job. Judging the second against what the
        // first left would report it as reaching nothing, which is the opposite of true.
        $present = $operation->parameterKeys();

        /** @var list<IgnoreParam> $unmatched */
        $unmatched = [];

        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            // Asked once: it reports an `in:` that names no location, and asking twice would say so twice.
            $locations = $this->locations($context, $ignore);
            $matched = false;

            foreach ($locations as $location) {
                $matched = $matched || in_array(ParameterDraft::keyFor($location, $ignore->name), $present, true);
                $operation->removeParameter($location, $ignore->name);
            }

            // An `in:` naming no location has already been reported as exactly that; adding "and the
            // name matched nothing" would ask the author to fix the half that was fine.
            if (! $matched && $locations !== []) {
                $unmatched[] = $ignore;
            }
        }

        $this->reportUnmatched($context, $unmatched, $operation->parameterKeys());
    }

    /**
     * The declarations that dropped nothing, reported for the action's own only — {@see UnmatchedDeclaration}
     * states why an inherited one is silent. `$published` is what the operation is left with, which is
     * what the reader can go and compare their spelling against.
     *
     * @param  list<IgnoreParam>  $unmatched
     * @param  list<string>  $published
     */
    private function reportUnmatched(RouteContext $context, array $unmatched, array $published): void
    {
        $direct = $context->attributes->direct(IgnoreParam::class);

        // Deduped: two identical declarations on one action are one mistake, and saying it twice would
        // make the reader look for a second one.
        $reported = [];

        foreach ($unmatched as $ignore) {
            $written = ParameterDraft::keyFor($ignore->in ?? '*', $ignore->name);

            if (! in_array($ignore, $direct, true) || in_array($written, $reported, true)) {
                continue;
            }

            $reported[] = $written;

            $context->components->addDiagnostic(UnmatchedDeclaration::parameter(
                $ignore,
                $published,
                $context->actionSource(),
                $context->route->signature(),
            ));
        }
    }

    /**
     * The locations one declaration names: all four when it names none, and the one it names otherwise,
     * matched case-insensitively — `in: 'Query'` says exactly what `in: 'query'` says, and a spelling the
     * tool can understand is not worth making the author look up.
     *
     * A value that names no location at all is the other thing: it dropped nothing, and it cannot be read
     * as any of four words, so it is reported rather than guessed at — quoting both of the author's
     * values through {@see PlainText}, the way its sibling report does, because a diagnostic message is
     * also emitted into `x-docuccino.diagnostics` and read back out of there by something else.
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
                PlainText::of($ignore->name),
                PlainText::of($ignore->in),
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: 'A parameter is in cookie, header, path or query — spelled in any case. Leave `in:` off to drop the name from every location.',
        ));

        return [];
    }
}
