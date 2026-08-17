<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use ReflectionClass;
use Throwable;

/**
 * The `#[ErrorComponent]` name an exception declares, and the class that declared it.
 *
 * PHP does not inherit class attributes, so the parent walk is this reader's: a base exception names
 * every subclass that names nothing itself, and the nearest declaration wins. Reading the whole
 * hierarchy is why a route that throws one records the hierarchy's files as fragment dependencies.
 */
final readonly class DeclaredErrorComponent
{
    public function __construct(
        public string $name,
        public string $declaredBy,
        public ?string $file = null,
        public ?int $line = null,
    ) {}

    /**
     * Whether a declaration may take the name a response already carries.
     *
     * It replaces the DEFAULT name — the one the error tiers derive from the status — and nothing else.
     * A producer that named the body said something a name on the exception class cannot: one exception
     * can render several bodies, and only whoever built one tells them apart — a registered mapper, or
     * the render method the body came back through, which claims its own `#[ErrorComponent]` as it
     * builds. That is the whole ordering: method anchor, then class anchor, then the status default.
     *
     * Whether the standing claim IS that default is a fact the response carries from the write — the
     * `$isStatusDefault` its producer passed — never one re-derived from the name: a render method that
     * deliberately declares `#[ErrorComponent("NotFound")]` on a 404 has named that body, and matching its
     * value against the default table would read the deliberate name as the absence of one and hand the
     * response to the class anchor instead.
     */
    public static function mayReplace(?string $claim, bool $claimIsStatusDefault): bool
    {
        return $claim === null || $claimIsStatusDefault;
    }

    /**
     * The declaration governing an exception FQCN, or null when neither it nor a parent carries one.
     *
     * Total, like the engine's reader for the same attribute on a render method: the attribute is never
     * instantiated, because `#[ErrorComponent(5)]` in application source is a typo whose `TypeError` names
     * the file it was written in — and a build that let that through would print the machine's absolute
     * paths into the document it emits. An argument that isn't a string is no declaration, and neither is
     * a hierarchy reflection cannot walk.
     */
    public static function on(string $fqcn): ?self
    {
        try {
            if (! class_exists($fqcn)) {
                return null;
            }

            for ($class = new ReflectionClass($fqcn); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getAttributes(ErrorComponent::class) as $attribute) {
                    $arguments = $attribute->getArguments();
                    $name = $arguments[0] ?? $arguments['name'] ?? null;
                    if (! is_string($name)) {
                        continue;
                    }

                    $file = $class->getFileName();
                    $line = $class->getStartLine();

                    return new self(
                        $name,
                        $class->getName(),
                        $file === false ? null : $file,
                        $line === false ? null : $line,
                    );
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
