<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigValues;
use Docuccino\Laravel\Registry\ConfigDiagnostics;

/**
 * Puts every key `docuccino.yaml` declares to the typed reader, so one holding something other than
 * what the shipped file writes there is refused rather than read as empty.
 *
 * The refusals are the reader's own ({@see ConfigValues}) and travel with it, so there is no
 * diagnostic here and a key two callers ask about is still one line. What is here is the ASKING.
 * Almost nothing in a document's bag reached the typed reader at all: `routes`, `security`, `tags`
 * and `representation` are read off a plain bag by whichever reader owns them, and a list or a path
 * is read with a helper that coerces — so `routes: 'api/*'` and `routes: { include: 'api/*' }` each
 * emptied a document's whole route filter and published every route in the application, in silence.
 *
 * What is a section and what type a value takes are read off the shipped file itself
 * ({@see DeclaredSettings::sections()}, {@see DeclaredSettings::valueTypes()}) rather than listed
 * here, so a setting added to the product cannot stay unasked. What is listed here is the two kinds
 * of key this pass deliberately says nothing about, and why each one owes no answer — a third kind,
 * the closed-set keywords, is read off {@see ConfiguredKeywords} rather than listed.
 *
 * @internal
 */
final class ConfiguredShapes
{
    /**
     * The {@see UnknownSettings::OPEN} subtrees whose members the author NAMES. The listed path is
     * still asked — it is our key, and a `schemes` holding a line of text is still wrong — but nothing
     * BELOW it is, because what looks like a declared path down there is an illustration out of the
     * shipped file. `security.schemes.bearer` is an example scheme name, not a setting.
     *
     * Only the SECTIONS need listing, because a section is the only thing descended into. `tags.map`,
     * `security.default` and `security.document` are author-keyed too and are values here, so nothing
     * walks into them either way. The OPEN entries deliberately absent are the two that are not
     * author-keyed at all: `documents.*.info` and `documents.*.servers` are OAS objects with Docuccino
     * keys of their own, and `info.title` is as much a setting as `engine.mode` is.
     *
     * @var list<string>
     */
    public const array AUTHOR_KEYED = [
        'documents.*.security.schemes',
        'documents.*.integrations.query_builder.filter_descriptions',
        'documents.*.representation.examples.formats',
        'lint.leakage.patterns',
    ];

    /**
     * The keys this pass says nothing about, as path => why. Descent CONTINUES through them, so a
     * declared key underneath is still asked — `info.description` admits two shapes and
     * `info.description.file` admits one.
     *
     * Every entry is a key where a single type would either report a correct file or name a fallback
     * the build does not take, and two of them are already reported by a diagnostic that says more
     * than a type ever could. A key whose values are a CLOSED SET is not listed here: it is skipped off
     * {@see ConfiguredKeywords::catalogue()}, so a keyword setting added to the product cannot stay
     * asked by both readers.
     *
     * @var array<string, string>
     */
    public const array UNSHAPED = [
        'documents.*.info.description' => 'a line of markdown or a { file: … } map, so neither shape alone is right',
        'documents.*.integrations.api_resources.wrap' => 'a switch OR the wrap key to force, so neither is right — and being a switch by default, no switch report covers it either',
        'documents.*.routes.filter' => 'a filter that cannot be applied stops the run (config.route-filter-unusable), so there is no document for a warning to reach',
        'extensions' => 'a scalar is read as a one-entry list, so it still works; every entry that contributed nothing is reported by config.extension-missing',
        'diagnostics.accept' => 'a scalar is read as a one-entry list, so it still works',
    ];

    /**
     * Ask about every key the file writes, recording a refusal on `$build`'s reader for each one whose
     * shape is not the one the shipped file declares.
     */
    public static function read(BuildConfig $build): void
    {
        self::walk($build->values(), $build->all(), []);
    }

    /**
     * @param  array<array-key, mixed>  $bag
     * @param  list<string>  $normal  the path with author-chosen names as `*`, which is what is judged
     */
    private static function walk(ConfigValues $values, array $bag, array $normal): void
    {
        $keyed = $normal !== [] && in_array($normal[count($normal) - 1], DeclaredSettings::KEYED_MAPS, true);

        foreach ($bag as $key => $value) {
            $childNormal = [...$normal, $keyed ? '*' : (string) $key];
            $path = implode('.', $childNormal);

            if (self::below($path)) {
                continue;
            }

            // A keyword setting is read and refused by {@see ConfiguredKeywords}, which names the set
            // the value missed — more than a type ever could. Asked here as well, one line to fix
            // would be reported twice, under two codes, with two different remedies.
            if (array_key_exists($path, ConfiguredKeywords::catalogue())) {
                continue;
            }

            $unshaped = array_key_exists($path, self::UNSHAPED);

            if (in_array($path, DeclaredSettings::sections(), true)) {
                $written = is_array($value) && ! array_is_list($value);

                // Asked one segment at a time, and never as a dotted path: a dot addresses structure,
                // and a document key is a word its author chose — which may hold one. A section this
                // pass says nothing about is not asked, but IS walked into when it holds keys, because
                // a key under it is declared in its own right.
                if ($unshaped && ! $written) {
                    continue;
                }

                $section = $values->map((string) $key);

                // A refused section answers no keys, so there is nothing to walk into.
                if ($written) {
                    self::walk($section, $value, $childNormal);
                }

                continue;
            }

            if ($unshaped) {
                continue;
            }

            match (DeclaredSettings::valueTypes()[$path] ?? null) {
                DeclaredSettings::TEXT => $values->string((string) $key),
                DeclaredSettings::LIST => $values->entries((string) $key),
                // Everything else owes no ask, and each for its own reason: a switch is read and
                // refused by {@see ConfiguredFlags} wherever it sits, a bag's keys are the author's, a
                // NONE states no type at all, and the file's one whole number is inside a list entry
                // so no key addresses it. A key nothing declares is {@see UnknownSettings}' to report,
                // and the rest of the config report is {@see ConfigDiagnostics}'. The keyword family is
                // gone before this, above — it is a TEXT key this pass would otherwise ask twice.
                default => null,
            };
        }
    }

    /** Whether `$path` sits below a subtree whose member names are the author's. */
    private static function below(string $path): bool
    {
        foreach (self::AUTHOR_KEYED as $open) {
            if (str_starts_with($path, $open.'.')) {
                return true;
            }
        }

        return false;
    }
}
