<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\DescriptionAppender;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Documents the authorization a `spatie/laravel-permission` middleware enforces. Each
 * `role:`/`permission:`/`role_or_permission:` middleware becomes an entry in the machine-readable
 * `x-permissions` member, plus a "Requires permission: …" line on the description. Writes at the
 * integration layer, so a docblock or attribute description still wins — `x-permissions` stays the
 * authoritative signal either way.
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
        // An op that opted out of auth carries `security: []`, which a permission line would contradict.
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
