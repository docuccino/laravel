<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Closure;
use Docuccino\Core\Inference\TypeEngineBuilder;

/**
 * Presence of the optional `docuccino/inference-phpstan` package. Static analysis is a build-time
 * tool, so the adapter must install without it: the engine's entry class is named by STRING and
 * probed with `class_exists` (the integration-gate pattern), never imported — a hard import would
 * fatal a production app that only requires `docuccino/laravel`.
 *
 * Tests swap the probe by binding an instance of this class into the container.
 */
final readonly class EnginePackage
{
    /** The engine package's {@see TypeEngineBuilder} entry point. */
    public const string BUILDER = 'Docuccino\Inference\PhpStan\Analysis\PhpStanTypeEngineBuilder';

    /** The one command that fixes an absent engine. */
    public const string INSTALL_COMMAND = 'composer require --dev docuccino/inference-phpstan';

    /** @param  (Closure(string): bool)|null  $probe  defaults to `class_exists` */
    public function __construct(
        private ?Closure $probe = null,
    ) {}

    public function installed(): bool
    {
        $probe = $this->probe ?? static fn (string $class): bool => class_exists($class);

        return $probe(self::BUILDER);
    }

    /** The engine's builder, or null when the package isn't installed. */
    public function builder(): ?TypeEngineBuilder
    {
        if (! $this->installed()) {
            return null;
        }

        $builder = self::BUILDER;

        return new $builder;
    }
}
