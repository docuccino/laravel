<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Provenance\ClassNames;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
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
     * Both halves of `attribute.unreadable` name something reflection supplied, and neither may be
     * published as it stands. The CAUSE: PHP allows `new` in an attribute's arguments, so what
     * instantiating one throws is not limited to the named errors PHP raises itself, and an anonymous
     * exception's `::class` spells the absolute file it was written in plus a counter of the anonymous
     * classes the PROCESS declared before it — {@see ClassNames} is where that becomes publishable. The
     * SITE: an action's symbol falls back to the FILE where there is no class, so an ordinary closure
     * route names one absolutely — {@see MessagePaths} is where that does.
     */
    public function __construct(
        private readonly ClassNames $classNames = new ClassNames(new RootRelativeSourcePathResolver('')),
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
                $onUnreadable?->__invoke($this->unreadable($attribute->getName(), $site, $cause, $routeSignature));
            }
        }
    }

    /** The one `attribute.unreadable` mint — a route's actions and a webhook class both report it. */
    private function unreadable(string $attribute, string $site, Throwable $cause, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.unreadable',
            message: sprintf(
                'The #[%s] on %s could not be instantiated and was ignored.',
                substr($attribute, strlen(self::NAMESPACE_PREFIX)),
                $this->messagePaths->relative($site),
            ),
            routeSignature: $routeSignature,
            help: sprintf('Its constructor threw %s. Check the arguments at that declaration against the attribute\'s constructor.', $this->classNames->of($cause)),
        );
    }
}
