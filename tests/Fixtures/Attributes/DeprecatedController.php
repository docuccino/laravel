<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Description;

/**
 * Every way an author can say WHY an operation is deprecated, one action each: the attribute's reason,
 * the docblock tag's trailing text, both at once, and the reason beside an attribute description that
 * replaces the docblock prose outright.
 */
final class DeprecatedController
{
    /**
     * Lists the widgets.
     *
     * The long version, from the docblock.
     */
    #[DeprecatedOperation(reason: 'Use /api/v2/widgets instead.')]
    public function attributeReason(): array
    {
        return [];
    }

    /**
     * Lists the widgets.
     *
     * The long version, from the docblock.
     */
    #[DeprecatedOperation(reason: 'Use /api/v2/widgets instead.')]
    #[Description(text: 'The consumer-facing version.')]
    public function reasonBesideDescription(): array
    {
        return [];
    }

    /**
     * Lists the widgets.
     *
     * @deprecated Use /api/v2/widgets instead.
     */
    #[DeprecatedOperation]
    public function bareAttributeWithDocblockReason(): array
    {
        return [];
    }

    /**
     * Lists the widgets.
     *
     * @deprecated The docblock's reason.
     */
    #[DeprecatedOperation(reason: 'The attribute\'s reason.')]
    public function bothReasons(): array
    {
        return [];
    }

    /**
     * Lists the widgets.
     */
    #[DeprecatedOperation]
    public function noReason(): array
    {
        return [];
    }
}
