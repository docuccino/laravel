<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\DescriptionAppender;

/**
 * Documents the authorization a `spatie/laravel-permission` middleware enforces (design §Phase 4).
 * Each `role:`/`permission:`/`role_or_permission:` middleware on the route becomes an entry in the
 * machine-readable `x-permissions` extension member, and a generated line is appended to the
 * operation description ("Requires permission: …"). Class_exists-guarded on the package's
 * `PermissionServiceProvider`. Writes at the integration layer, so a docblock/attribute description
 * still wins (in which case the structured `x-permissions` remains the authoritative signal).
 */
final class PermissionExtension implements OperationExtension
{
    public function __construct(
        private readonly PermissionMiddlewareParser $parser = new PermissionMiddlewareParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // An operation opted out of auth documents no security requirement, so the permission line
        // and x-permissions member would be inconsistent alongside `security: []` — skip it.
        if ($context->attributes->has(Unauthenticated::class)) {
            return;
        }

        $requirements = $this->requirements($context);
        if ($requirements === []) {
            return;
        }

        $contribution = Contribution::integration('permission', $context->actionSource());

        $operation->set('x-permissions', array_map(static fn (PermissionRequirement $r): array => $r->toArray(), $requirements), $contribution);

        $this->appendDescription($operation, $requirements, $contribution);
    }

    /**
     * @return list<PermissionRequirement>
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

        return $requirements;
    }

    /**
     * @param  list<PermissionRequirement>  $requirements
     */
    private function appendDescription(OperationDraft $operation, array $requirements, Contribution $contribution): void
    {
        $lines = implode("\n\n", array_map(static fn (PermissionRequirement $r): string => $r->describe(), $requirements));

        DescriptionAppender::append($operation, $lines, $contribution);
    }
}
