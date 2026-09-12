<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use DateTimeImmutable;
use JsonSerializable;

/**
 * An application's own date class: a subclass of PHP's, stating its own JSON form. What that form IS
 * cannot be read off the declaration, and this one writes a UK date to prove the point — a document
 * that claimed `format: date-time` here would mark the server's own bytes invalid.
 */
final class StampedDate extends DateTimeImmutable implements JsonSerializable
{
    public function jsonSerialize(): string
    {
        return $this->format('d/m/Y');
    }
}
