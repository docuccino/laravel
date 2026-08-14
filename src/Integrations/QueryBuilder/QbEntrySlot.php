<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Inference\SourceLocation;
use PhpParser\Node;

/**
 * The call site an allow-list entry still being folded resolves against — everything the folded value
 * itself cannot carry: which allow-list it belongs to, the kind a bare string would get, the config method
 * to name in a diagnostic, the node a leading comment would hang off, where it was written, and the
 * modifiers written around the call.
 */
final readonly class QbEntrySlot
{
    public function __construct(
        public string $bucket,
        public string $defaultKind,
        public string $method,
        public ?Node $itemNode,
        public SourceLocation $location,
        public QbModifiers $modifiers,
    ) {}
}
