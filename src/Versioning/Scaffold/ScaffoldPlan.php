<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

/**
 * What one diff came to: the change classes the vocabulary can express, and a sentence for every real
 * difference it cannot.
 *
 * The second half is not a footnote. A verb the vocabulary has not got, or a schema no class produces,
 * has to be SAID — a scaffold that silently emitted nothing for it would read as "nothing changed
 * there", which is the one answer that costs the author a version document they think is complete.
 *
 * @internal
 */
final readonly class ScaffoldPlan
{
    /**
     * @param  list<ScaffoldedChange>  $changes  sorted by class name
     * @param  list<string>  $gaps  sorted, each a difference the vocabulary does not express
     */
    public function __construct(
        public array $changes = [],
        public array $gaps = [],
    ) {}
}
