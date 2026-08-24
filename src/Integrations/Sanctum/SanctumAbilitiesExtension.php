<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\DescriptionAppender;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Documents the Sanctum token abilities an operation requires. Each `abilities:`/`ability:` middleware (or
 * the deprecated `CheckScopes`/`CheckForAnyScope` FQCN forms) and each `#[Abilities]` attribute becomes an
 * entry in the machine-readable `x-abilities` member, plus a "Requires token ability: …" line on the
 * description. `sanctumToken` is an HTTP bearer scheme, so OAS can't carry abilities as scopes — hence the
 * extension member. Skipped for `#[Unauthenticated]`, where an abilities line would contradict
 * `security: []`.
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
     * Middleware requirements first, then `#[Abilities]` ones — the attribute is an all-of requirement.
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
