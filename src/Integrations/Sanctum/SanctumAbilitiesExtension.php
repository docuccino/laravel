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
     * Keyed by the JSON each requirement publishes — the dedupe docs/design/uir-and-extensions.md §2
     * "A contested published slot" separates from a merge — so an ability stated by both the middleware
     * and the attribute, or by two spellings of one middleware, is one requirement; two that differ
     * anywhere are two the server enforces separately and both are published.
     *
     * @return list<AbilityRequirement>
     */
    private function requirements(RouteContext $context): array
    {
        $requirements = [];
        foreach ($context->route->middleware as $middleware) {
            $requirement = $this->parser->parse($middleware);
            if ($requirement !== null) {
                $requirements[json_encode($requirement->toArray(), JSON_THROW_ON_ERROR)] = $requirement;
            }
        }

        foreach ($context->attributes->all(Abilities::class) as $attribute) {
            if ($attribute->abilities !== []) {
                $requirement = new AbilityRequirement(AbilityRequirement::ALL, $attribute->abilities);
                $requirements[json_encode($requirement->toArray(), JSON_THROW_ON_ERROR)] = $requirement;
            }
        }

        return array_values($requirements);
    }

    /**
     * One line per distinct sentence: a single-ability requirement reads the same whichever way `match`
     * reads it, so an `any` and an `all` over one ability say one thing and say it once.
     *
     * @param  list<AbilityRequirement>  $requirements
     */
    private function appendDescription(OperationDraft $operation, array $requirements, Contribution $contribution): void
    {
        $sentences = array_unique(array_map(static fn (AbilityRequirement $r): string => $r->describe(), $requirements));

        DescriptionAppender::append($operation, implode("\n\n", $sentences), $contribution);
    }
}
