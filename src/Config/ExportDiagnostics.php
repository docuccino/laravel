<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Registry\ConfigDiagnostics;

/**
 * Names and phrases the structured problems {@see DocumentConfig::exportTargetIssues()} reports.
 *
 * These are errors, not info: a document that cannot say where its artifacts go has nothing sensible
 * to write, so `docuccino:export` refuses before it builds anything. They deliberately do NOT live in
 * {@see ConfigDiagnostics}, which runs inside the build — an error raised
 * there would print but still exit 0 under the default `--fail-on=none`, after a full analysis, and
 * would fire again on every viewer request and cache warm, where nothing is being written at all.
 *
 * @internal
 */
final class ExportDiagnostics
{
    /**
     * @return list<Diagnostic>
     */
    public static function for(DocumentConfig $document): array
    {
        return array_map(
            static fn (array $issue): Diagnostic => self::diagnose($document->key, $issue),
            $document->exportTargetIssues(),
        );
    }

    /**
     * Whether any of $diagnostics would stop a write.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    public static function fatal(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{index: int, problem: string, detail: string}  $issue
     */
    private static function diagnose(string $key, array $issue): Diagnostic
    {
        $at = sprintf('documents.%s.export.targets', $key);
        $entry = $issue['index'] >= 0 ? sprintf('%s.%d', $at, $issue['index']) : $at;
        $detail = $issue['detail'];

        return match ($issue['problem']) {
            'empty' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-no-targets',
                message: sprintf('%s is set but holds no targets — remove it to fall back to export.path, or list at least one {format, path} entry.', $at),
            ),
            'shape' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-target-shape',
                message: sprintf('%s is not a {format, path} entry with both members set to a non-empty string%s.', $entry, $detail === '' ? '' : sprintf(' (got %s)', $detail)),
            ),
            'unknown-format' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-unknown-format',
                message: sprintf("%s names an unknown format '%s' (valid values: %s).", $entry, $detail, implode(', ', Formats::ids())),
            ),
            'yaml-unsupported' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-yaml-unsupported',
                message: sprintf('%s writes %s, but that format has no YAML serialisation — give it a .json path rather than a .yaml file holding JSON.', $entry, $detail),
            ),
            'duplicate-path' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-duplicate-path',
                message: sprintf("%s writes '%s', which an earlier target already writes — the later one would clobber it.", $entry, $detail),
            ),
            'duplicate-format' => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-duplicate-format',
                message: sprintf("%s is a second '%s' target — one target per format, so --format and the viewer's artifact both resolve to one file.", $entry, $detail),
            ),
            'path-ignored' => new Diagnostic(
                severity: Severity::Info,
                code: 'config.export-path-ignored',
                message: sprintf("documents.%s.export.targets supersedes export.path, so '%s' is not written — delete the path key.", $key, $detail),
            ),
            default => new Diagnostic(
                severity: Severity::Error,
                code: 'config.export-target-shape',
                message: sprintf('%s could not be read as a {format, path} entry.', $entry),
            ),
        };
    }
}
