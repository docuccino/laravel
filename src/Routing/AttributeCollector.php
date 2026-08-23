<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\AttributeSet;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Throwable;

/**
 * Collects the Docuccino attributes declared on a route's action: method-level first, then the
 * controller class and its parents nearest-first (the {@see AttributeSet} preserves this
 * most-specific-first order, so a child's declaration beats the base controller's — the same
 * nearest-wins walk `#[ErrorComponent]` gets on an exception hierarchy). Only
 * `Docuccino\Attributes\*` instances are materialised; foreign attributes are ignored, and one that
 * fails to instantiate is skipped and handed to `$onUnreadable` as the `attribute.unreadable`
 * diagnostic this class is the single mint for.
 *
 * @internal
 */
final class AttributeCollector
{
    private const NAMESPACE_PREFIX = 'Docuccino\\Attributes\\';

    /**
     * @param  Closure(Diagnostic): void|null  $onUnreadable
     */
    public function collect(ReflectedAction $action, ?Closure $onUnreadable = null, ?string $routeSignature = null): AttributeSet
    {
        $set = new AttributeSet;

        $this->addFrom($set, $action->reflection, $action->actionRef->symbol(), $onUnreadable, $routeSignature);

        $class = $action->controllerClass;
        if ($class !== null && class_exists($class)) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $this->addFrom($set, $reflection, $reflection->getName(), $onUnreadable, $routeSignature);
            }
        }

        return $set;
    }

    /**
     * The attributes on ONE reflection, walking nowhere — what a `#[Webhook]` class collects, since
     * inheriting would hand every subclass of a base event the base's name to fight over.
     *
     * @param  ReflectionClass<object>|ReflectionFunctionAbstract  $reflection
     * @param  Closure(Diagnostic): void|null  $onUnreadable
     */
    public function collectOne(ReflectionClass|ReflectionFunctionAbstract $reflection, string $site, ?Closure $onUnreadable = null): AttributeSet
    {
        $set = new AttributeSet;

        $this->addFrom($set, $reflection, $site, $onUnreadable, null);

        return $set;
    }

    /**
     * @param  ReflectionClass<object>|ReflectionFunctionAbstract  $reflection
     * @param  Closure(Diagnostic): void|null  $onUnreadable
     */
    private function addFrom(AttributeSet $set, ReflectionClass|ReflectionFunctionAbstract $reflection, string $site, ?Closure $onUnreadable, ?string $routeSignature): void
    {
        foreach ($reflection->getAttributes() as $attribute) {
            if (! str_starts_with($attribute->getName(), self::NAMESPACE_PREFIX)) {
                continue;
            }

            try {
                $set->add($attribute->newInstance());
            } catch (Throwable $cause) {
                $onUnreadable?->__invoke(self::unreadable($attribute->getName(), $site, $cause, $routeSignature));
            }
        }
    }

    /** The one `attribute.unreadable` mint — a route's actions and a webhook class both report it. */
    private static function unreadable(string $attribute, string $site, Throwable $cause, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.unreadable',
            message: sprintf(
                'The #[%s] on %s could not be instantiated and was ignored.',
                substr($attribute, strlen(self::NAMESPACE_PREFIX)),
                $site,
            ),
            routeSignature: $routeSignature,
            help: sprintf('Its constructor threw %s. Check the arguments at that declaration against the attribute\'s constructor.', $cause::class),
        );
    }
}
