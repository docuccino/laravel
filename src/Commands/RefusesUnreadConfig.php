<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Config\ExportDiagnostics;
use Illuminate\Console\Command;

/**
 * Stops a command that would build a document out of configuration the build could not read.
 *
 * One situation, four ways into it, and they are one class rather than four cases: `docuccino.yaml`
 * missing while the build settings sit in `config/docuccino.php`, or there and unreadable, or there
 * and not YAML, or there and holding something that is not a map of settings. Every one of them
 * leaves the document assembled from defaults instead of from what its author wrote, which is not a
 * finding to weigh — it is the configuration not having been read.
 *
 * So the check is an ERROR raised about the configuration itself, whichever of the four raised it, and
 * it happens before the build and outside `--fail-on`, on {@see ExportDiagnostics}' precedent. That
 * flag gates what a build FOUND; its quietest setting is also its default, so an error printed from
 * inside the build exits 0 after a full analysis with the wrong artifact already on disk.
 *
 * `config.file-misnamed` is deliberately not here. It is a WARNING, because there is no
 * `docuccino.yaml` at all — zero configuration is a supported state and the document from defaults is
 * genuinely the product — and what its severity should be is a question about the diagnostic, not
 * about this gate.
 *
 * `docuccino:migrate-config` is exempt because it IS the remedy — it writes `docuccino.yaml` from the
 * settings this refusal is about — and `docuccino:install` because it runs that one on an application
 * in this state. `docuccino:clear` is exempt because it reads no configuration: it empties caches,
 * which is the one thing still worth doing here.
 *
 * @mixin Command
 */
trait RefusesUnreadConfig
{
    /**
     * The renderer every gating command already uses, so the refusal prints as the diagnostic it is —
     * code, severity, help and reference link — rather than as a second phrasing of one report.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    abstract protected function renderDiagnostics(string $document, array $diagnostics): void;

    /** True — having said why — when the configuration this build would read is not the configured one. */
    protected function abortIfConfigUnread(): bool
    {
        $build = app(BuildConfig::class);

        // The file itself: unreadable, not YAML, or not a map. Its own reader has already decided which
        // of its states are errors, so this reads the severity rather than re-deciding the state.
        $unread = array_values(array_filter(
            $build->file()->diagnostics,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity === Severity::Error,
        ));

        // Never both: a build key left behind is only a refusal where there is no file at all, and an
        // absent file raises nothing above a warning. So the two answers name two different files, and
        // the report is filed against whichever one its reader has to open.
        $notMigrated = ConfigSplit::notMigrated($build);

        if ($unread === [] && $notMigrated === null) {
            return false;
        }

        // Naming a document key here would suggest the document is the thing that is wrong.
        $this->renderDiagnostics(
            $unread === [] ? 'config/docuccino.php' : ConfigFile::NAME,
            $unread === [] ? [$notMigrated] : $unread,
        );

        return true;
    }
}
