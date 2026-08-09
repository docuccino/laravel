<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\OptionallyAuthenticated;
use Docuccino\Attributes\Security;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Patch\Contribution;

/**
 * The attribute security layer (design §7): `#[Security]` and `#[OptionallyAuthenticated]` override or
 * relax `security` at attribute precedence (40), beating anything the integrations inferred from
 * middleware (20). Repeated `#[Security]` attributes form an OR-list, any one of which satisfies the
 * operation; `#[OptionallyAuthenticated]` prepends the empty requirement, giving OAS's
 * `security: [{}, …]`.
 *
 * Ordered last in the Security phase so a lone `#[OptionallyAuthenticated]` reads a draft that already
 * carries the integration layer's inferred security.
 */
#[ExtensionOrder(priority: -100)]
final class AttributeSecurityExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $declared = $this->declared($context);
        $optional = $context->attributes->has(OptionallyAuthenticated::class);

        if ($declared === null && ! $optional) {
            return;
        }

        $contribution = Contribution::attribute($context->actionSource());

        if (! $optional) {
            $operation->setSecurity($declared, $contribution);

            return;
        }

        // Anonymous OR the authenticated alternative — a declared `#[Security]` list if there is one,
        // else whatever a lower layer inferred from middleware.
        $base = $declared ?? $this->inferredSecurity($operation);
        $operation->setSecurity($this->withAnonymous($base), $contribution);
    }

    /**
     * The OR-list declared via `#[Security]` in source order, or null when there are none.
     *
     * @return list<array<string, list<string>>>|null
     */
    private function declared(RouteContext $context): ?array
    {
        $attributes = $context->attributes->all(Security::class);
        if ($attributes === []) {
            return null;
        }

        return array_map(static fn (Security $s): array => [$s->scheme => $s->scopes], $attributes);
    }

    /**
     * Whatever a lower layer already resolved onto the draft, or null if nothing has.
     *
     * @return list<array<string, mixed>>|null
     */
    private function inferredSecurity(OperationDraft $operation): ?array
    {
        $security = $operation->resolvedField('security');
        if (! is_array($security)) {
            return null;
        }

        /** @var list<array<string, mixed>> $filtered */
        $filtered = array_values(array_filter($security, 'is_array'));

        return $filtered;
    }

    /**
     * Prepends the anonymous requirement, dropping any empty entry already in the base so `{}` appears
     * exactly once, first.
     *
     * @param  list<array<string, mixed>>|null  $base
     * @return list<array<string, mixed>>
     */
    private function withAnonymous(?array $base): array
    {
        $requirement = [[]];
        foreach ($base ?? [] as $entry) {
            if ($entry !== []) {
                $requirement[] = $entry;
            }
        }

        return $requirement;
    }
}
