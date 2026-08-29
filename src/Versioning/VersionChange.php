<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\RenamedResponseField;

/**
 * One declared API version change, as the build reads it: the version it shipped in, the sentence
 * written for the consumer, the response-field renames it declares, and the operations it is scoped to.
 * The class body is never read and never executed — the imperative half belongs to the application's
 * own runtime.
 *
 * @internal
 */
final readonly class VersionChange
{
    /**
     * @param  class-string  $class
     * @param  list<RenamedResponseField>  $renames  in the order they are written on the class
     * @param  list<string>  $selectors  the operation selectors its #[AppliesTo] declare; empty means
     *                                   every operation the schema appears in
     */
    public function __construct(
        public string $class,
        public string $since,
        public string $description,
        public array $renames,
        public array $selectors = [],
    ) {}
}
