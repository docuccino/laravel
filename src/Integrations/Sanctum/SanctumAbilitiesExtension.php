<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\DescriptionAppender;

/**
 * Documents the Sanctum token abilities an operation requires (auth audit #5). The `abilities:` /
 * `ability:` middleware (and the deprecated `CheckScopes` / `CheckForAnyScope` FQCN forms), plus a
 * `#[Abilities]` attribute for body-checked abilities, each become an entry in the machine-readable
 * `x-abilities` extension member, and a generated "Requires token ability: …" line is appended to
 * the description — mirroring the spatie-permission integration's `x-permissions`. Because
 * `sanctumToken` is an HTTP bearer scheme, OAS can't carry abilities as scopes. Skipped for
 * `#[Unauthenticated]` (a public op documents no requirement, so an abilities line would be
 * inconsistent alongside `security: []`).
 */
final class SanctumAbilitiesExtension implements OperationExtension
{
    public function __construct(
        private readonly AbilityMiddlewareParser $parser = new AbilityMiddlewareParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->attributes->has(Unauthenticated::class)) {
            return;
        }

        $requirements = $this->requirements($context);
        if ($requirements === []) {
            return;
        }

        $contribution = Contribution::integration('sanctum-abilities', $context->actionSource());

        $operation->set('x-abilities', array_map(static fn (AbilityRequirement $r): array => $r->toArray(), $requirements), $contribution);

        $this->appendDescription($operation, $requirements, $contribution);
    }

    /**
     * The ability requirements from the route middleware, followed by any declared via `#[Abilities]`
     * (an all-of requirement of the listed abilities).
     *
     * @return list<AbilityRequirement>
     */
    private function requirements(RouteContext $context): array
    {
        $requirements = [];
        foreach ($context->route->middleware as $middleware) {
            $requirement = $this->parser->parse($middleware);
            if ($requirement !== null) {
                $requirements[] = $requirement;
            }
        }

        foreach ($context->attributes->all(Abilities::class) as $attribute) {
            if ($attribute->abilities !== []) {
                $requirements[] = new AbilityRequirement(AbilityRequirement::ALL, $attribute->abilities);
            }
        }

        return $requirements;
    }

    /**
     * @param  list<AbilityRequirement>  $requirements
     */
    private function appendDescription(OperationDraft $operation, array $requirements, Contribution $contribution): void
    {
        $lines = implode("\n\n", array_map(static fn (AbilityRequirement $r): string => $r->describe(), $requirements));

        DescriptionAppender::append($operation, $lines, $contribution);
    }
}
