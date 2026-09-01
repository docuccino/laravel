<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

/**
 * What one verb came to across every node the document publishes its schema at. A schema can be
 * published more than once — the hoisted component and an inline copy of it — so the answer is the
 * STRONGEST thing seen: a verb that edited one copy and found nothing to do at another has applied.
 *
 * @internal
 */
enum VerbOutcome: int
{
    /** No node anywhere carries the identity — the document publishes no such schema. */
    case Unresolved = 0;

    /** The schema is published and no longer has the field the verb names. */
    case Absent = 1;

    /** The field is there, and the edit would change nothing or would change the wrong thing. */
    case Declined = 2;

    case Applied = 3;

    public function strongest(self $other): self
    {
        return $other->value > $this->value ? $other : $this;
    }
}
