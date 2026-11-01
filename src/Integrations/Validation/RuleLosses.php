<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

/**
 * What one field lost to rules the build saw and could not read ({@see UnreadRule}): each that can only
 * refuse values, named once, and the first whose content could loosen the rest, which gives up the field.
 */
final class RuleLosses
{
    /** @var list<string> */
    private array $unread = [];

    private ?string $opaque = null;

    public function add(string $label, bool $narrows): void
    {
        if (! $narrows) {
            $this->opaque ??= $label;
        } elseif (! in_array($label, $this->unread, true)) {
            $this->unread[] = $label;
        }
    }

    /** @return list<string> */
    public function unread(): array
    {
        return $this->unread;
    }

    public function opaque(): ?string
    {
        return $this->opaque;
    }
}
