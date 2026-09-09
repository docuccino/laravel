<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Support\ConfiguredKeyword;
use Docuccino\Core\Support\Hydrate;

/**
 * Every closed-set keyword setting Docuccino's own configuration carries, and the one diagnostic that
 * says a key held a value outside its set. {@see ConfiguredKeyword} is the reading; this is the
 * catalogue of what gets read, and the report on what could not be.
 *
 * The shape is {@see ConfiguredFlags}' exactly, for the same reason: a keyword is read where it is
 * needed — in a policy value object, in a config factory, inside a viewer request — and most of those
 * places have nowhere to put a diagnostic. So the paths, their sets and their defaults are stated
 * once, {@see forDocument()} reports the document-scoped ones through the build's config pass and
 * {@see forInstall()} the rest. Nothing here restates a set: every one is the constant the reader
 * itself uses, so a set that grows in the reader grows in the message.
 *
 * A refusal is a WARNING, on the same terms as {@see ConfiguredFlags}': the build did not merely
 * ignore a setting nobody reads, it DISCARDED an instruction someone wrote — how nullability is
 * expressed, how an operation is identified, whether the document publishes error responses at all —
 * and it cannot fire on anything but a value someone typed.
 *
 * @phpstan-type KeywordSetting array{0: non-empty-list<string>, 1: string}
 *
 * @internal
 */
final class ConfiguredKeywords
{
    /**
     * The one diagnostic code for a setting holding a value outside its closed set, wherever in the
     * configuration it sits.
     *
     * ONE code and not one per setting, because it is one fact with one remedy: the value names nothing
     * the product has, so the documented default was used, and the fix is to write one of the values
     * the message lists. A code per producer would make an author who wants to accept the family list
     * every one of them, and would mint another code for every keyword setting added after — which is
     * how the same fact came to be reported at two severities under two names with no reason for the
     * difference. `engine.mode` keeps its own code, for the reason {@see INSTALL_KEYWORDS} gives.
     */
    public const string CODE = 'config.unknown-value';

    /**
     * Keyword settings inside a `documents.<key>` bag in `docuccino.yaml`, as dotted path => [the
     * values it takes, the default it answers]. `viewer.source` is not here because it comes out of
     * the other file — {@see VIEWER_KEYWORDS}.
     *
     * `error_responses` answers `default` here and `none` when the key is absent, which is not a
     * disagreement: only a WRITTEN key is ever refused, so the default named here is the one the
     * document actually used ({@see DocumentConfigFactory::errorResponses()}).
     *
     * @var array<string, KeywordSetting>
     */
    private const array DOCUMENT_KEYWORDS = [
        'error_responses' => [DocumentConfig::ERROR_RESPONSES, DocumentConfig::ERROR_RESPONSES_DEFAULT],
        'representation.enums.naming' => [RepresentationPolicy::ENUM_NAMINGS, RepresentationPolicy::DEFAULT_ENUM_NAMING],
        'representation.filters' => [RepresentationPolicy::FILTER_STYLES, RepresentationPolicy::DEFAULT_FILTER_STYLE],
        'representation.nullable' => [RepresentationPolicy::NULLABLE_STYLES, RepresentationPolicy::DEFAULT_NULLABLE],
        'representation.operation_id' => [RepresentationPolicy::OPERATION_IDS, RepresentationPolicy::DEFAULT_OPERATION_ID],
        'tags.default_strategy' => [DocumentConfig::TAG_STRATEGIES, DocumentConfig::TAG_STRATEGY_DEFAULT],
        'versioning' => [DocumentConfig::VERSIONING_POLICIES, DocumentConfig::VERSIONING_DEFAULT],
    ];

    /**
     * Keyword settings in the framework config's `viewer` bag, as leaf => [set, default].
     *
     * Read off {@see DocumentConfig::$viewer} rather than the raw bag, for the reason
     * {@see ConfiguredFlags::VIEWER_FLAGS} gives: the viewer is the one member of a document config
     * that comes from `config/docuccino.php`, so a refusal looked for in `docuccino.yaml` could never
     * fire. The reported PATH still reads `viewer.source`, which is where its author will go to fix it.
     *
     * @var array<string, KeywordSetting>
     */
    private const array VIEWER_KEYWORDS = [
        'source' => [ViewerConfig::SOURCES, ViewerConfig::SOURCE_DEFAULT],
    ];

    /**
     * Keyword settings outside every document in `docuccino.yaml`, as dotted path => [set, default].
     *
     * `engine.mode` is not here on purpose. Every setting in this catalogue falls back to the default
     * the shipped file shows beside it, and its refusal can therefore be produced by one reader that
     * knows the key, the set and that default. `engine.mode` does not: it falls back to whatever will
     * still analyse rather than to the documented default, it is reported only where an engine is
     * installed to analyse with, and `DOCUCCINO_ENGINE` can supply the value — so a message telling its
     * author to go and fix a key in `docuccino.yaml` would name a file the value need not be in. It
     * keeps `engine.mode-unknown`, whose help names the variable instead.
     *
     * @var array<string, KeywordSetting>
     */
    private const array INSTALL_KEYWORDS = [
        'on_route_error' => [DocumentConfig::ON_ROUTE_ERRORS, DocumentConfig::ON_ROUTE_ERROR_DEFAULT],
    ];

    /**
     * Every keyword setting there is, as dotted path => [set, default] — the document ones under
     * `documents.*`, so a caller reading the catalogue sees the paths an author writes.
     *
     * Public because the guards over this family have to enumerate it, and a guard that enumerated it
     * by hand would be silent about the setting somebody forgot to add.
     *
     * @return array<string, KeywordSetting>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (self::DOCUMENT_KEYWORDS as $path => $setting) {
            $catalogue['documents.*.'.$path] = $setting;
        }

        foreach (self::VIEWER_KEYWORDS as $leaf => $setting) {
            $catalogue['documents.*.viewer.'.$leaf] = $setting;
        }

        return [...$catalogue, ...self::INSTALL_KEYWORDS];
    }

    /**
     * Every keyword this document configures that holds a value outside its set, as diagnostics — the
     * paths above and the viewer's own.
     *
     * Two bags rather than one, because a document config is assembled from two files and only the raw
     * bag is the build's own.
     *
     * @return list<Diagnostic>
     */
    public static function forDocument(DocumentConfig $document): array
    {
        $diagnostics = [];

        foreach (self::DOCUMENT_KEYWORDS as $path => [$accepted, $default]) {
            $diagnostic = self::report($document->raw, $path, $default, $accepted);
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
        }

        foreach (self::VIEWER_KEYWORDS as $leaf => [$accepted, $default]) {
            $diagnostic = self::report(['viewer' => $document->viewer], 'viewer.'.$leaf, $default, $accepted);
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $diagnostics;
    }

    /**
     * The keyword settings outside any document, reported once per build.
     *
     * @return list<Diagnostic>
     */
    public static function forInstall(): array
    {
        $config = app(BuildConfig::class)->all();

        $diagnostics = [];
        foreach (self::INSTALL_KEYWORDS as $path => [$accepted, $default]) {
            $diagnostic = self::report($config, $path, $default, $accepted);
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $diagnostics;
    }

    /**
     * The refusal at one dotted path, or null when the key holds a keyword (or nothing).
     *
     * @param  array<string, mixed>  $config
     * @param  non-empty-list<string>  $accepted
     */
    private static function report(array $config, string $path, string $default, array $accepted): ?Diagnostic
    {
        $keyword = self::read($config, $path, $default, $accepted);
        $refusal = $keyword->refusal($path);

        return $refusal === null ? null : new Diagnostic(
            severity: Severity::Warning,
            code: self::CODE,
            message: $refusal,
            help: $keyword->help(),
        );
    }

    /**
     * One reading of a DOTTED path: walk down to the bag the leaf sits in, then read the leaf.
     *
     * @param  array<string, mixed>  $config
     * @param  non-empty-list<string>  $accepted
     */
    private static function read(array $config, string $path, string $default, array $accepted): ConfiguredKeyword
    {
        $segments = explode('.', $path);
        $leaf = (string) array_pop($segments);

        $bag = $config;
        foreach ($segments as $segment) {
            $bag = Hydrate::map($bag[$segment] ?? null);
        }

        return ConfiguredKeyword::read($bag, $leaf, $default, $accepted);
    }
}
