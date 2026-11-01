<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ArrayItemRules;

/** What an API token may do: a backed enum, so its value set is a component of its own. */
enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
