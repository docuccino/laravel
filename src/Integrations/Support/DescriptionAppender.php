<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Patch\Contribution;

/**
 * The description-merge invariant shared by the security integrations that annotate an operation's
 * description with a requirement note (Sanctum abilities, spatie permissions): read the currently
 * resolved description and append the addition with a `\n\n` separator, so a note joins the prose an
 * earlier producer set rather than replacing it. Written once here instead of per integration.
 */
final class DescriptionAppender
{
    public static function append(OperationDraft $operation, string $addition, Contribution $by): void
    {
        $current = $operation->resolvedField('description');
        $description = is_string($current) && $current !== '' ? $current."\n\n".$addition : $addition;

        $operation->setDescription($description, $by);
    }
}
