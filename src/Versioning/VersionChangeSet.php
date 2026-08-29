<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Versioning\VersionOrder;

/**
 * What one document's changes directory came to: the changes, the order their versions are written in,
 * and what could not be read on the way. The order travels WITH the changes because the collector had
 * to settle it to sort them at all — resolving it a second time at the point of use is how one build
 * ends up with two opinions about which of two versions is older.
 *
 * A null order means the document's versions could not be ordered; the collector has already said so.
 *
 * @internal
 */
final readonly class VersionChangeSet
{
    /**
     * @param  list<VersionChange>  $changes  newest first, then by class name
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $changes,
        public ?VersionOrder $order,
        public array $diagnostics,
    ) {}

    /**
     * The changes that shipped strictly AFTER `$version`, newest first — the ones an older document has
     * to undo. Asked here rather than compared at the point of use, because the comparison is the order
     * this set was sorted by and re-deciding it elsewhere is how a list ends up sorted one way and
     * filtered another.
     *
     * @return list<VersionChange>
     */
    public function after(string $version): array
    {
        $order = $this->order;
        if ($order === null) {
            return [];
        }

        $later = [];
        foreach ($this->changes as $change) {
            if (($order->compare($change->since, $version) ?? 0) > 0) {
                $later[] = $change;
            }
        }

        return $later;
    }
}
