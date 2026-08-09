<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

/**
 * The closure-registration surface (design §6). `Docuccino::extend(fn (Registrar $r) => …)` is
 * invoked at resolve time with a fresh Registrar, letting one closure contribute several
 * extensions in any order without the registry snapshotting early.
 */
final class Registrar
{
    /**
     * @var list<class-string|object>
     */
    private array $extensions = [];

    /**
     * @param  class-string|object  $extension
     */
    public function add(string|object $extension): self
    {
        $this->extensions[] = $extension;

        return $this;
    }

    /**
     * @return list<class-string|object>
     */
    public function all(): array
    {
        return $this->extensions;
    }
}
