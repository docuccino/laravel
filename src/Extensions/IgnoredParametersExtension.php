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
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
use Docuccino\Laravel\Support\ParameterLocations;
use Docuccino\Laravel\Support\UnmatchedDeclaration;

/**
 * Applies `#[IgnoreParam]`. It is the only subtractive parameter pass, so it runs in Finalize, after
 * every producer that could write a parameter: the parameter phase's own extensions, the request phase's
 * validation recovery, and the parameter attributes. Removing a node before its producer runs removes
 * nothing, because the producer creates it again.
 *
 * It sits ahead of the example pass inside Finalize, so an `#[Example(parameter: …)]` naming something
 * this dropped reports a missing target rather than illustrating a parameter the document no longer has.
 *
 * A bracketed name (`#[IgnoreParam(name: 'filter[opaque]')]`) drops the matching member of a deepObject
 * container where that representation publishes one, through {@see DeepObjectMembers}'s reading — shared
 * with the producers that write those members, so a name cannot be a member for one and a parameter for
 * the other.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class IgnoredParametersExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $members = new DeepObjectMembers($operation);

        // Two passes, because which declarations matched is decided against what stands BEFORE any
        // removal: two naming one address both did their job, and judging the second against what the
        // first left would report it as having reached nothing.
        $present = $operation->parameterKeys();

        /** @var list<array{0: IgnoreParam, 1: list<string>, 2: bool}> $judged */
        $judged = [];

        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            // Asked once: it reports an `in:` that names no location, and asking twice would say so twice.
            $locations = $this->locations($context, $ignore);

            // Judged by the walk that is about to drop it, so reporting and removing answer alike.
            $matched = in_array('query', $locations, true) && $members->publishes($ignore->name);

            foreach ($locations as $location) {
                $matched = $matched || in_array(ParameterDraft::keyFor($location, $ignore->name), $present, true);
            }

            $judged[] = [$ignore, $locations, $matched];
        }

        /** @var list<IgnoreParam> $unmatched */
        $unmatched = [];

        foreach ($judged as [$ignore, $locations, $matched]) {
            foreach ($locations as $location) {
                $operation->removeParameter($location, $ignore->name);
            }

            // Dropping the container instead would take away every other filter the author kept.
            if (in_array('query', $locations, true)) {
                $members->remove($ignore->name);
            }

            // An `in:` naming no location has already been reported as exactly that; adding "and the
            // name matched nothing" would ask the author to fix the half that was fine.
            if (! $matched && $locations !== []) {
                $unmatched[] = $ignore;
            }
        }

        $this->reportUnmatched($context, $unmatched, $this->published($operation, $members));
    }

    /**
     * The addresses a declaration can name: every parameter key, and every deepObject member under the
     * bracketed name that drops it — the half a bracketed typo needs, since the container alone hands the
     * reader back the address they already tried.
     *
     * @return list<string>
     */
    private function published(OperationDraft $operation, DeepObjectMembers $members): array
    {
        $keys = $operation->parameterKeys();

        foreach ($members->memberNames() as $member) {
            $keys[] = ParameterDraft::keyFor('query', $member);
        }

        sort($keys, SORT_STRING);

        return $keys;
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
     * values as they were written; {@see Diagnostic} is what makes them safe to print.
     *
     * @return list<string>
     */
    private function locations(RouteContext $context, IgnoreParam $ignore): array
    {
        if ($ignore->in === null) {
            return ParameterLocations::all();
        }

        $location = ParameterLocations::read($ignore->in);
        if ($location !== null) {
            return [$location];
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
