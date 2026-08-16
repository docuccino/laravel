<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use ReflectionClass;

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
     * can render several bodies, and only the mapper that built one tells them apart. Static because it
     * is a question about the response and the status; which declaration is asking makes no difference.
     */
    public static function mayReplace(?string $claim, string $status): bool
    {
        return $claim === null || $claim === FrameworkExceptionTable::componentName($status);
    }

    /** The declaration governing an exception FQCN, or null when neither it nor a parent carries one. */
    public static function on(string $fqcn): ?self
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        for ($class = new ReflectionClass($fqcn); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes(ErrorComponent::class) as $attribute) {
                $file = $class->getFileName();
                $line = $class->getStartLine();

                return new self(
                    $attribute->newInstance()->name,
                    $class->getName(),
                    $file === false ? null : $file,
                    $line === false ? null : $line,
                );
            }
        }

        return null;
    }
}
