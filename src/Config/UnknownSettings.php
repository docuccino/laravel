<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Registry\ConfigDiagnostics;

/**
 * Every key in a project's `docuccino.yaml` that names no setting, and the one Docuccino key it was
 * probably meant to be.
 *
 * A misspelled key used to do nothing and say nothing while the build ran on defaults, which is
 * sharper in YAML than it was in framework config: the PHP file was edited in place with every key
 * already written, whereas this one is authored from scratch, and a block at the wrong indentation
 * does not merely misspell one key — it REPARENTS a whole bag. `lint:` indented one level too far
 * becomes `documents.default.lint`, and every rule under it goes quiet together.
 *
 * The set of real keys is derived from the shipped file ({@see DeclaredSettings}) rather than listed
 * here, so a setting cannot be added to the product and stay unknown to this.
 *
 * Two boundaries keep it off correct configuration, and getting either wrong would be worse than the
 * silence it replaces:
 *
 * - {@see OPEN} subtrees hold names the AUTHOR chooses, or OpenAPI objects passed through verbatim.
 *   Nothing below one is judged.
 * - {@see DEFERRED} subtrees have their own report on their own member names, so this one says nothing
 *   about those names and everything about what is written under them.
 *
 * One diagnostic per unknown key, and not one per kind: each carries its own suggestion, which is
 * most of the value. The count stays proportional to the mistakes rather than to the file, because a
 * key that is unknown is not descended into — a reparented bag is one report, not one per rule.
 *
 * @internal
 */
final class UnknownSettings
{
    /** A key nobody reads, whatever else the file gets right. */
    public const string CODE = 'config.unknown-setting';

    /**
     * Subtrees whose members the author names, or that hold verbatim OpenAPI. The listed path is still
     * checked; nothing below it is.
     *
     * Every entry is a place a Docuccino key and an author's key would be indistinguishable, so a
     * broad one here is silence and a missing one is a report on correct configuration.
     *
     * @var list<string>
     */
    public const array OPEN = [
        // The OAS Info Object, published as written: `contact`, `license`, `termsOfService` and
        // whatever else a later OAS minor adds are all read by whoever consumes the document.
        'documents.*.info',
        // OAS Server Objects, likewise.
        'documents.*.servers',
        // Your scheme names, each holding an OAS Security Scheme Object.
        'documents.*.security.schemes',
        // OAS Security Requirement lists, keyed by the scheme names above.
        'documents.*.security.default',
        'documents.*.security.document',
        // Raw tag => display tag, both halves yours.
        'documents.*.tags.map',
        // Token => label heuristics, both halves yours.
        'lint.leakage.patterns',
        // Filter kind => your own sentence. The kinds are a closed set, reported by
        // `config.unknown-filter-kind` ({@see ConfigDiagnostics}).
        'documents.*.integrations.query_builder.filter_descriptions',
        // JSON Schema `format` => your own sample. The formats are the spec's, not ours.
        'documents.*.representation.examples.formats',
    ];

    /**
     * Subtrees whose own member names another diagnostic already reports. Their members are not named
     * here — two reports on one typo teach a reader to skim both — and what is written UNDER a member
     * is checked as usual.
     *
     * @var array<string, string> the subtree => the code that covers its member names
     */
    public const array DEFERRED = [
        'documents.*.integrations' => 'config.unknown-integration',
    ];

    /**
     * Everything in `$build`'s file that names no setting, in file order.
     *
     * @return list<Diagnostic>
     */
    public static function report(BuildConfig $build): array
    {
        $unknown = [];
        self::walk($build->all(), [], [], $unknown);

        return array_map(self::diagnose(...), $unknown);
    }

    /**
     * @param  array<array-key, mixed>  $bag
     * @param  list<string>  $literal  the path as the author wrote it, which is what a message names
     * @param  list<string>  $normal  the same path with author-chosen names as `*`, which is what is judged
     * @param  list<array{written: string, path: string}>  $unknown
     */
    private static function walk(array $bag, array $literal, array $normal, array &$unknown): void
    {
        $list = array_is_list($bag);
        $keyed = $literal !== [] && in_array($literal[count($literal) - 1], DeclaredSettings::KEYED_MAPS, true);
        $deferred = array_key_exists(implode('.', $normal), self::DEFERRED);

        foreach ($bag as $key => $value) {
            $childLiteral = [...$literal, (string) $key];
            $childNormal = [...$normal, $list || $keyed ? '*' : (string) $key];
            $path = implode('.', $childNormal);

            // A list INDEX is not a key an author could misspell, so only what is inside one is judged.
            if (! $list && ! in_array($path, DeclaredSettings::shipped(), true)) {
                // Not descended into either way: a bag at the wrong indentation is ONE mistake, and
                // naming every rule under it would be the same report five times over.
                if (! $deferred) {
                    $unknown[] = ['written' => implode('.', $childLiteral), 'path' => $path];
                }

                continue;
            }

            if (is_array($value) && ! self::isOpen($path)) {
                self::walk($value, $childLiteral, $childNormal, $unknown);
            }
        }
    }

    /** Whether nothing below `$path` is Docuccino's to judge. */
    private static function isOpen(string $path): bool
    {
        return in_array($path, self::OPEN, true);
    }

    /**
     * @param  array{written: string, path: string}  $entry
     */
    private static function diagnose(array $entry): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: self::CODE,
            // A warning rather than info, on the same terms as its neighbours: the build did not
            // ignore a switch nobody reads, it DISCARDED an instruction somebody wrote, and it cannot
            // fire on anything but a key an author typed.
            message: sprintf(
                '%s names no setting Docuccino reads, so nothing written under it does anything.',
                $entry['written'],
            ),
            help: self::help($entry['written'], $entry['path']),
        );
    }

    /**
     * The one key it was probably meant to be, in the order the answers are certain.
     *
     * Facts before guesses. A key belonging to the OTHER file is one, and so is this key's own name
     * standing at exactly one other depth — which is the reparented block, the mistake YAML makes that
     * framework config could not. Only then a near miss by spelling, which is a guess and reads as one.
     * Anything else gets the reference page rather than an invention.
     */
    private static function help(string $written, string $path): string
    {
        if (in_array($path, ConfigSplit::FRAMEWORK_KEYS, true)) {
            return sprintf(
                'That one is read from config/docuccino.php and not from here: the framework loads it while it BOOTS and a viewer request reads it, and neither can afford to parse this file. That file keeps %s, and this one keeps everything else.',
                implode(', ', ConfigSplit::FRAMEWORK_KEYS),
            );
        }

        $misplaced = self::elsewhere($path);
        if ($misplaced !== null) {
            return sprintf(
                'The setting called %s sits at %s — check the indentation of the block this key opens, which is what moves a whole bag under the wrong parent.',
                self::leaf($path),
                $misplaced,
            );
        }

        $misspelled = self::nearest($path);
        if ($misspelled !== null) {
            return sprintf('Did you mean %s?', self::spelled($written, $misspelled));
        }

        return sprintf(
            'Delete it, or check it against the configuration reference. %s is Docuccino\'s own file, so a key it does not declare is not handed on to anything else.',
            ConfigFile::NAME,
        );
    }

    /**
     * The declared key at the same place in the file within a few edits of this one, or null.
     *
     * Siblings only: `lint.tags` and `documents.*.tags` are one edit apart and neither is a plausible
     * misspelling of the other, so a suggestion drawn from the whole set would send its reader to the
     * wrong half of the file.
     */
    private static function nearest(string $path): ?string
    {
        $leaf = self::leaf($path);

        // levenshtein() is byte-wise and cheap; a key long enough to make that a bad idea is not a typo.
        if (strlen($leaf) > 64) {
            return null;
        }

        $best = null;
        $distance = 4;

        foreach (self::siblings($path) as $candidate) {
            $edits = levenshtein(strtolower($leaf), self::leaf($candidate));

            if ($edits < $distance) {
                $best = $candidate;
                $distance = $edits;
            }
        }

        return $best;
    }

    /**
     * The one declared path whose own last segment is this key — the same key at another depth.
     *
     * Only where it is the ONE such path. `enabled` stands under a dozen bags, so naming the first of
     * them would send its reader somewhere arbitrary; every top-level bag a misindented block can fall
     * into (`lint`, `cache`, `engine`, `extensions`, `diagnostics`, `on_route_error`) is unique, which
     * is the population this answer is for.
     */
    private static function elsewhere(string $path): ?string
    {
        $leaf = self::leaf($path);

        $matches = array_values(array_filter(
            DeclaredSettings::shipped(),
            static fn (string $candidate): bool => self::leaf($candidate) === $leaf,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Every declared key under the same parent as `$path`.
     *
     * @return list<string>
     */
    private static function siblings(string $path): array
    {
        $parent = self::parent($path);
        $depth = substr_count($path, '.');

        return array_values(array_filter(
            DeclaredSettings::shipped(),
            static fn (string $candidate): bool => substr_count($candidate, '.') === $depth
                && self::parent($candidate) === $parent,
        ));
    }

    /** The suggestion written the way the author writes their own file, with the real names back in. */
    private static function spelled(string $written, string $suggestion): string
    {
        $writtenSegments = explode('.', $written);
        $segments = explode('.', $suggestion);

        foreach ($segments as $index => $segment) {
            if ($segment === '*' && isset($writtenSegments[$index])) {
                $segments[$index] = $writtenSegments[$index];
            }
        }

        return implode('.', $segments);
    }

    private static function parent(string $path): string
    {
        $at = strrpos($path, '.');

        return $at === false ? '' : substr($path, 0, $at);
    }

    private static function leaf(string $path): string
    {
        $at = strrpos($path, '.');

        return $at === false ? $path : substr($path, $at + 1);
    }
}
