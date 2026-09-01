<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;

/**
 * One change class the scaffolder would write: its name, the version it shipped in, the sentence a
 * consumer reads, and the one verb it declares.
 *
 * One verb per class on purpose. A diff knows which fields moved and nothing about which of them are
 * one story, so a class per difference gives each its own `description` — and the description is the
 * whole reason to scaffold at all. Merging two of them afterwards is a paragraph of editing; splitting
 * one sentence back into two is a rewrite.
 *
 * @internal
 */
final readonly class ScaffoldedChange
{
    /**
     * @param  string  $schema  the class the verb names, which is also the class whose module the change
     *                          is written beside ({@see ChangePlacement})
     * @param  list<string>  $imports  FQCNs the file imports, {@see ApiVersionChange} excluded — the stub
     *                                 imports that one itself
     * @param  ?string  $note  what the author still has to supply, for the command to report; null when
     *                         the scaffold said everything it could
     * @param  list<string>  $scope  the `#[AppliesTo]` attributes it declares, already written out;
     *                               empty for a change that applies wherever its schema appears
     */
    public function __construct(
        public string $class,
        public string $schema,
        public string $since,
        public string $description,
        public string $verb,
        public array $imports,
        public ?string $note = null,
        public array $scope = [],
    ) {}

    /**
     * The same change, narrowed to the operations `#[AppliesTo]` names. Handed the attributes already
     * written out, because {@see ChangeScaffolder} owns how one is spelled and one speller of that is
     * enough; what belongs here is the import the scope drags in with it.
     *
     * @param  list<string>  $scope
     */
    public function scopedTo(array $scope): self
    {
        return $scope === [] ? $this : new self(
            class: $this->class,
            schema: $this->schema,
            since: $this->since,
            description: $this->description,
            verb: $this->verb,
            imports: [...$this->imports, AppliesTo::class],
            note: $this->note,
            scope: $scope,
        );
    }

    /** The file this change is written to, under `$directory`. */
    public function file(string $directory): string
    {
        return rtrim($directory, '/').'/'.$this->class.'.php';
    }
}
