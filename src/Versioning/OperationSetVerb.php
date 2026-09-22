<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;

/**
 * A verb whose subject is which operations the document publishes AT ALL, as {@see ApiVersionTransformer}
 * applies it.
 *
 * The third kind, and the distinction is the same one that separates the first two. A {@see VersionVerb}
 * names a class and is applied wherever the document carries that schema; an {@see OperationVerb} edits
 * one operation in place. This one removes a whole node, so it has nothing to edit and no outcome to
 * accumulate — a node is there or it is not — and the walk it needs is the document's own operation
 * index rather than a schema's identity.
 *
 * It carries its own selector for the same reason {@see OperationVerb} carries its own parameter name:
 * what it names is the thing itself, not where an edit should land, so `#[AppliesTo]` has nothing to say
 * about it.
 *
 * @internal
 */
interface OperationSetVerb
{
    /** The selector naming the operations this verb is about, as the author wrote it. */
    public function selector(): string;

    /** What this verb names, as a diagnostic spells it — `the operation "POST /api/invoices"`. */
    public function declares(): string;

    /** The selector named no operation this document publishes, so there was nothing to remove. */
    public function unreached(VersionChange $change): Diagnostic;

    /**
     * ONE operation the verb would not remove, and `$operation` is what a selector calls it. Per
     * operation, because a refusal is a fact about the operation it was refused for.
     */
    public function refused(string $operation, VersionChange $change): Diagnostic;
}
