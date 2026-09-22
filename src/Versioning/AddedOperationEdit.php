<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * `#[AddedOperation]` as the transformer applies it: the operations the selector names are taken out of
 * the derived document, because the versions before this change did not serve them.
 *
 * Nothing about the operation is read on the way out — an operation that is not there needs no shape —
 * which is what makes this the one verb with no declaration to get wrong beyond the selector itself.
 *
 * @internal
 */
final readonly class AddedOperationEdit implements OperationSetVerb
{
    public function __construct(private string $operation) {}

    public function selector(): string
    {
        return $this->operation;
    }

    public function declares(): string
    {
        return sprintf('the operation "%s"', $this->operation);
    }

    public function unreached(VersionChange $change): Diagnostic
    {
        return VerbDiagnostics::targetMissing($change, sprintf(
            'says this version added %s, which this document publishes no operation for',
            $this->declares(),
        ), 'operation');
    }

    /**
     * An operation published through a path item another path addresses too. Both ARE one node, so
     * removing the method would remove it for a path this change says nothing about — the same refusal
     * a scoped schema edit makes, and refused rather than half-applied for the same reason.
     */
    public function refused(string $operation, VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-unforkable',
            message: sprintf(
                '%s could not be narrowed as written: "%s" is published through a path item shared with operations the change does not name, so removing it would remove them too and it was left in the document.',
                $change->class,
                $operation,
            ),
            help: 'Name every operation behind that shared path item, and the whole of it is removed — or publish the path item for this operation alone.',
        );
    }
}
