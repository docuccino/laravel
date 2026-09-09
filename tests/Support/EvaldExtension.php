<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

/**
 * An `OperationExtension` no file holds, for the rows about what the cache does when it cannot key one.
 *
 * `eval()`'d code reports a file like `/path/Test.php(12) : eval()'d code`, which is a path no
 * `is_file()` matches — and a dependency manifest records a file that isn't there as ABSENT, which reads
 * FRESH for as long as it stays absent. So there is nothing here to key a fragment on, and unlike a tag
 * mapper there is no per-route bag to refuse with: the entry keys every fragment of the document.
 */
final class EvaldExtension
{
    /** The extension's FQCN. Declared by {@see ensure()} and by nothing else. */
    public const string EXTENSION = 'Docuccino\\Laravel\\Tests\\Temp\\EvaldExtension';

    /** The value it writes into every operation, so a row can read its answer out of the document. */
    public const string VALUE = 'Evald';

    /** Declare it, if this process hasn't already — one process may declare a class once. */
    public static function ensure(): string
    {
        if (! class_exists(self::EXTENSION, false)) {
            eval('namespace Docuccino\Laravel\Tests\Temp; class EvaldExtension implements \Docuccino\Core\Extensions\Contracts\OperationExtension { public function phase(): \Docuccino\Core\Extensions\Contracts\OperationPhase { return \Docuccino\Core\Extensions\Contracts\OperationPhase::Finalize; } public function handle(\Docuccino\Core\Draft\OperationDraft $operation, \Docuccino\Core\Extensions\Context\RouteContext $context): void { $operation->set("x-scratch", "Evald", \Docuccino\Core\Patch\Contribution::attribute()); } }');
        }

        return self::EXTENSION;
    }
}
