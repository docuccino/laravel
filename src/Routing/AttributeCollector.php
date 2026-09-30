<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\UnreadableAttribute;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * Collects the Docuccino attributes declared on a route's action: method-level first, then the
 * controller class and its parents nearest-first (the {@see AttributeSet} preserves this
 * most-specific-first order, so a child's declaration beats the base controller's — the same
 * nearest-wins walk `#[ErrorComponent]` gets on an exception hierarchy) — and marks the class walk's
 * declarations INHERITED, so a reader can tell what the author wrote on the action itself. Only
 * `Docuccino\Attributes\*` instances are materialised; foreign attributes are ignored, and one that
 * fails to instantiate is skipped and handed to `$onUnreadable` as `attribute.unreadable`.
 *
 * The guarantee everything reading an attribute leans on is narrower than "nothing of the application
 * runs", and this is where it is stated. PHP requires every attribute argument to be a constant
 * expression, so a closure or a method call cannot be written in one at all — but `new` can be, and
 * its constructor RUNS during the instantiation below, before the parameter type gets to refuse the
 * object; a declaring file in weak mode will even coerce a `Stringable` into a string parameter and
 * keep what `__toString()` returned. So the scalar-only signatures on the attributes are a TYPE
 * guard, not an execution guard: what makes a document deterministic is that an attribute argument is
 * a constant somebody wrote, not that nothing there could run.
 *
 * @internal
 */
final class AttributeCollector
{
    private const NAMESPACE_PREFIX = 'Docuccino\\Attributes\\';

    /**
     * Both halves of `attribute.unreadable` name something reflection supplied, and neither may be
     * published as it stands. The CAUSE is {@see UnreadableAttribute}'s to make publishable. The SITE:
     * an action's symbol falls back to the FILE where there is no class, so an ordinary closure route
     * names one absolutely — {@see MessagePaths} is where that does.
     */
    public function __construct(
        private readonly MessagePaths $messagePaths = new MessagePaths(new RootRelativeSourcePathResolver('')),
    ) {}

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
                $this->addFrom($set, $reflection, $reflection->getName(), $onUnreadable, $routeSignature, inherited: true);
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
    private function addFrom(AttributeSet $set, ReflectionClass|ReflectionFunctionAbstract $reflection, string $site, ?Closure $onUnreadable, ?string $routeSignature, bool $inherited = false): void
    {
        $declarations = array_values(array_filter(
            $reflection->getAttributes(),
            static fn (ReflectionAttribute $attribute): bool => str_starts_with($attribute->getName(), self::NAMESPACE_PREFIX),
        ));
        if ($declarations === []) {
            return;
        }

        [$instances, $diagnostics] = UnreadableAttribute::instantiate($declarations, $this->messagePaths->relative($site), $routeSignature);
        foreach ($instances as $instance) {
            $set->add($instance, $inherited);
        }
        foreach ($diagnostics as $diagnostic) {
            $onUnreadable?->__invoke($diagnostic);
        }
    }
}
