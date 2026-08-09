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
 * The attribute security layer (design §7): `#[Security]` and `#[OptionallyAuthenticated]` override
 * or relax the per-operation `security` requirement at the attribute precedence (40), so they win
 * over anything inferred from middleware by the Sanctum/Passport/config integrations (20).
 *
 * - Each `#[Security(scheme, scopes)]` is one alternative; repeated they form an OR-list
 *   `[{schemeA: scopesA}, {schemeB: scopesB}]` (any one satisfies the operation).
 * - `#[OptionallyAuthenticated]` prepends the empty (anonymous) requirement to whatever was declared
 *   here or inferred by a lower layer, yielding OAS's `security: [{}, …]`.
 *
 * Ordered LAST in the Security phase (low priority) so that, when only `#[OptionallyAuthenticated]`
 * is present, the requirement it reads back off the draft already reflects the integration layer's
 * inferred security.
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

        // Anonymous OR the declared/inferred requirement. A declared `#[Security]` list takes
        // precedence as the "authenticated" alternative; otherwise fall back to whatever a lower
        // layer inferred from middleware.
        $base = $declared ?? $this->inferredSecurity($operation);
        $operation->setSecurity($this->withAnonymous($base), $contribution);
    }

    /**
     * The OR-list declared via `#[Security]` (source order, most-specific first), or null when none.
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
     * The security requirement resolved so far on the draft (the integration/config layer's inferred
     * value), or null when nothing has set one.
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
     * Prepend the empty (anonymous) requirement, dropping any empty entry already in the base so the
     * `{}` alternative appears exactly once and first.
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
