<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

/**
 * Where one scaffolded change is written, and the sentence saying why there.
 *
 * The reason travels with the directory because it is reported for every change without exception. A
 * default that picks a module on the author's behalf and does not say so is worse than one that always
 * writes to the same place: the file is still discovered wherever it lands, so a wrong module is
 * invisible until somebody extracts one and its history goes with the other half.
 *
 * @internal
 */
final readonly class ChangeDestination
{
    /**
     * @param  string  $directory  absolute
     * @param  string  $reason  a clause, printed after the directory
     */
    public function __construct(
        public string $directory,
        public string $reason,
    ) {}
}
