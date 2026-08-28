<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\NameList;
use Docuccino\Core\Support\PlainText;

/**
 * The one report for an author-supplied name that matched nothing — `#[IgnoreParam]` naming a parameter
 * this operation does not document, `#[IgnoreResponse]` naming a status no producer would have written,
 * `#[InDocs]` naming a document nobody configured, `#[PathParameter]` naming a segment the route has no
 * template variable for.
 *
 * A name that matches nothing leaves no evidence of its own: a parameter that was never there and a
 * parameter that was dropped both leave the same document, and a route pinned to a document that does
 * not exist reads exactly like a route somebody meant to keep out. So an author who typo'd a name, or
 * kept one through a rename, sees exactly what a working declaration produces. All four members say the
 * same three things — the declaration as written, that it took no effect, and what there WAS to name so
 * the typo is visible beside it — so they say them from here rather than four times.
 *
 * Warning, not info: the document is not wrong (nothing was dropped, so nothing is missing that should
 * be there), but the author asked for something and did not get it, which is a request refused rather
 * than a build that widened. It is also the level the neighbouring refusals already use —
 * `attribute.ignore-param-location`, `attribute.error-component-unread`. The one exception is
 * {@see pathParameter()}, which is an Error and says there why.
 *
 * Where a declaration is INHERITED from a controller class rather than written on the action, only the
 * members whose class-level form has an ordinary reading stay silent — the ignores and the path
 * parameter, where an author covers a key or a segment only some of the class's actions have.
 * `#[InDocs]` has no such reading: a key nobody configured is wrong wherever it is written, so it is
 * reported once for the key rather than once per route.
 */
final class UnmatchedDeclaration
{
    /**
     * A name that matched no parameter. `$published` is what the operation is left documenting, as
     * `in:name` keys — read AFTER the pass has done its removals, because the remedy has to name what
     * the document actually publishes rather than what it held mid-build.
     *
     * @param  list<string>  $published
     */
    public static function parameter(IgnoreParam $ignore, array $published, ?Source $source, ?string $routeSignature): Diagnostic
    {
        $declaration = $ignore->in === null
            ? sprintf('#[IgnoreParam(name: "%s")]', PlainText::of($ignore->name))
            : sprintf('#[IgnoreParam(name: "%s", in: "%s")]', PlainText::of($ignore->name), PlainText::of($ignore->in));

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.ignore-param-unmatched',
            message: $declaration.' dropped nothing: this operation documents no such parameter. '.self::documenting('parameters', $published),
            source: $source,
            routeSignature: $routeSignature,
            help: 'Correct the name to one this operation documents, or delete the declaration — a parameter that was renamed keeps its old spelling only in the attribute. A key only some of a controller\'s actions take belongs on the class, where an action that never documented it is not a mistake.',
        );
    }

    /**
     * A status nothing would have written. `$published` is the statuses the operation is left
     * documenting.
     *
     * @param  list<string>  $published
     */
    public static function response(int $status, array $published, ?Source $source, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.ignore-response-unmatched',
            message: sprintf(
                '#[IgnoreResponse(status: %d)] dropped nothing: no producer would have written a %d response for this operation. %s',
                $status,
                $status,
                self::documenting('responses', $published),
            ),
            source: $source,
            routeSignature: $routeSignature,
            help: 'Correct the status to one this operation documents, or delete the declaration. An ignore names one status, so a response the document publishes as a RANGE — `3XX` for a redirect nothing pins to a code — is not one it can drop. A status only some of a controller\'s actions answer with belongs on the class, where an action that never answers with it is not a mistake.',
        );
    }

    /**
     * A document key nobody configured. `$routes` is every route signature the key is written on and
     * `$configured` the keys that do exist, both sorted by their callers — this fires once for the KEY
     * rather than once per route, so the message has to name where to go.
     *
     * `$stranded` says the routes below have no OTHER key that names a configured document either, which
     * is the difference between a dead key beside a working one and a route in no document at all.
     *
     * @param  list<string>  $routes
     * @param  list<string>  $configured
     */
    public static function document(string $key, array $routes, array $configured, bool $stranded): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.in-docs-unknown',
            message: sprintf(
                '#[InDocs] names the document "%s", which is not configured, so the key pins nothing%s. It is written on %s. %s',
                PlainText::of($key),
                $stranded ? ', and every route below is left out of every document' : '',
                NameList::of($routes) ?? 'no route',
                self::configured($configured),
            ),
            help: 'Correct the key against that list, or delete the declaration — a document renamed in config keeps its old key only in the attribute. #[InDocs] is an allow-list, so a route whose keys name no configured document is excluded from all of them; #[ExcludeFromDocs] is the way to say that on purpose.',
        );
    }

    /**
     * A `#[PathParameter]` naming no `{segment}` of the route template. `$template` is the segments the
     * route does have.
     *
     * Error, not warning, and the only member here that is: OAS requires every `in: path` parameter to
     * correspond to a template variable, so publishing one would be an invalid document — and unlike an
     * unresolvable security requirement, which is a true fact with nowhere to point, this parameter
     * describes nothing the server accepts. Withholding it therefore loses nothing true, so the document
     * stays valid and this says what was refused.
     *
     * @param  list<string>  $template
     */
    public static function pathParameter(string $name, array $template, ?Source $source, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Error,
            code: 'attribute.path-parameter-unmatched',
            message: sprintf(
                '#[PathParameter(name: "%s")] documented nothing: this route\'s template has no {%s} segment, and a path parameter OpenAPI has no template variable for would make the document invalid. %s',
                PlainText::of($name),
                PlainText::of($name),
                self::template($template),
            ),
            source: $source,
            routeSignature: $routeSignature,
            help: 'Correct the name to a segment of the route\'s own URI, or delete the declaration — a segment that was renamed keeps its old spelling only in the attribute. A query, header or cookie parameter that is not in the URI is #[QueryParameter], #[HeaderParameter] or #[CookieParameter]; only a path parameter has to be in the template. A segment only some of a controller\'s actions have belongs on the class, where an action without it is not a mistake.',
        );
    }

    /**
     * What the operation is left documenting. `$plural` is the caller's own noun, so the sentence never
     * has to work out which member is asking.
     *
     * @param  list<string>  $published
     */
    private static function documenting(string $plural, array $published): string
    {
        $listing = NameList::of($published);

        return $listing === null
            ? sprintf('It documents no %s at all.', $plural)
            : sprintf('It documents %s.', $listing);
    }

    /**
     * @param  list<string>  $configured
     */
    private static function configured(array $configured): string
    {
        $listing = NameList::of($configured);

        // Reachable: `documents` can be configured empty, and then a build has no document to run at all
        // — which is a configuration problem this message must not misreport as a spelling one.
        return $listing === null
            ? 'No documents are configured at all.'
            : sprintf('The configured documents are %s.', $listing);
    }

    /**
     * @param  list<string>  $template
     */
    private static function template(array $template): string
    {
        $listing = NameList::of(array_map(static fn (string $name): string => '{'.$name.'}', $template));

        return $listing === null
            ? 'It has no path parameters at all.'
            : sprintf('It has %s.', $listing);
    }
}
