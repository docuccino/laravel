<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Docuccino\Attributes\BodyParameter;

/**
 * Takes the notices whose partition is read off a copied key, each under an operation-level declaration —
 * which keeps its body inline, so its rules are published with no partition proved at all.
 */
final class InlineNoticeController
{
    #[BodyParameter('trace', type: 'string')]
    public function notifyRouted(StoreRoutedNoticeRequest $request): void {}

    #[BodyParameter('trace', type: 'string')]
    public function notifyDialled(StoreDialledNoticeRequest $request): void {}
}
