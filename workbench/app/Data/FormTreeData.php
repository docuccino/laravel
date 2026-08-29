<?php

declare(strict_types=1);

namespace Workbench\App\Data;

/**
 * A form that contains forms. The shape refers to itself, which is the one shape that cannot be given a
 * private copy at an operation — the copy would contain the shared component again.
 */
final class FormTreeData
{
    /**
     * @param  list<FormTreeData>  $children
     */
    public function __construct(
        public int $id,
        public string $title,
        public array $children,
    ) {}
}
