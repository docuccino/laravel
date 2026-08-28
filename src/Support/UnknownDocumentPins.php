<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Attributes\InDocs;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Laravel\Config\ConfiguredDocuments;

/**
 * Collects the `#[InDocs]` keys that name no configured document, for the two readers of the attribute —
 * a route's controller and a `#[Webhook]` class — and turns them into one diagnostic per KEY.
 *
 * Per key rather than per site because a key nobody configured is one mistake wherever it is written,
 * and `#[InDocs]` reads down the controller class, so one class-level typo covers every action of the
 * class. Reporting per site would say the same thing thirty times about one word.
 *
 * A pin's effect is invisible downstream — the route or the webhook is simply not there — so nothing
 * later in the build could notice it. That is why the reader accumulates rather than reporting on the
 * spot: the sites a key covers are only known once the whole walk is done.
 *
 * @internal
 */
final class UnknownDocumentPins
{
    /**
     * Unknown key → the sites it is written on, in encounter order.
     *
     * @var array<string, list<string>>
     */
    private array $sites = [];

    /**
     * The unknown keys whose sites named no configured document at all — the difference between a dead
     * key beside a working one and a route in no document whatsoever.
     *
     * @var array<string, true>
     */
    private array $stranded = [];

    public function __construct(
        private readonly ConfiguredDocuments $documents = new ConfiguredDocuments,
    ) {}

    /** `$site` is what the message sends the reader to — a route signature, or a webhook's class. */
    public function record(InDocs $inDocs, string $site): void
    {
        $unknown = [];
        $known = 0;

        foreach ($inDocs->documents as $key) {
            if ($this->documents->has($key)) {
                $known++;
            } elseif (! in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        foreach ($unknown as $key) {
            if (! in_array($site, $this->sites[$key] ?? [], true)) {
                $this->sites[$key][] = $site;
            }

            if ($known === 0) {
                $this->stranded[$key] = true;
            }
        }
    }

    /**
     * What was recorded, emptied as it is read. Sorted by key, and each key's sites sorted too, so the
     * report is a function of what was found and never of the order the router or the filesystem
     * happened to hand it over in.
     *
     * @return list<Diagnostic>
     */
    public function take(): array
    {
        $sites = $this->sites;
        $stranded = $this->stranded;
        $this->sites = [];
        $this->stranded = [];

        ksort($sites);

        $configured = $this->documents->keys();

        $diagnostics = [];
        foreach ($sites as $key => $written) {
            sort($written);

            $diagnostics[] = UnmatchedDeclaration::document((string) $key, $written, $configured, isset($stranded[$key]));
        }

        return $diagnostics;
    }
}
