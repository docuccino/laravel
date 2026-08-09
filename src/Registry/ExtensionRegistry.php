<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Closure;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Illuminate\Contracts\Container\Container;

/**
 * The late-bound extension registry (design §6 — the Scramble boot-trap, designed away).
 *
 * `extend()` merely appends a class-string, an instance, or a {@see Registrar} closure; nothing
 * is read until {@see resolve()} runs at build time (post-boot by definition). Because there is
 * no method that returns the list before resolution, an early snapshot is impossible — a
 * registration made in any provider's `register()`/`boot()`, or even after the whole app has
 * booted, is picked up. Config `extensions` merge in at resolve time too.
 */
final class ExtensionRegistry
{
    /**
     * @var list<class-string|object|Closure>
     */
    private array $registrations = [];

    /**
     * @param  class-string|object|Closure  $extension
     */
    public function extend(string|object $extension): void
    {
        $this->registrations[] = $extension;
    }

    /**
     * Resolve every registration (plus config extensions and the built-in defaults) into a
     * partitioned, per-contract-sorted set. Called once per build; never at boot.
     *
     * @param  list<class-string|object>  $defaults  the built-in extensions (dogfooding the API)
     * @param  list<class-string|object>  $configExtensions  from `config('docuccino.extensions')`
     */
    public function resolve(Container $container, array $defaults, array $configExtensions): ResolvedExtensions
    {
        $instances = [];
        foreach ([...$defaults, ...$this->registrations, ...$configExtensions] as $registration) {
            foreach ($this->expand($registration, $container) as $instance) {
                $instances[] = $instance;
            }
        }

        $sorter = new ExtensionSorter;

        return new ResolvedExtensions(
            routeResolvers: $sorter->sort($this->partition($instances, RouteResolver::class)),
            operationExtensions: $sorter->sort($this->partition($instances, OperationExtension::class)),
            typeToSchema: $sorter->sort($this->partition($instances, TypeToSchema::class)),
            exceptionToResponse: $sorter->sort($this->partition($instances, ExceptionToResponse::class)),
            documentTransformers: $sorter->sort($this->partition($instances, DocumentTransformer::class)),
            ruleTransformers: $sorter->sort($this->partition($instances, RuleTransformer::class)),
            responseAnalysisTargets: $sorter->sort($this->partition($instances, ResponseAnalysisTarget::class)),
            responseStatusResolvers: $sorter->sort($this->partition($instances, ResponseStatusResolver::class)),
            payloadMediaTypeResolvers: $sorter->sort($this->partition($instances, PayloadMediaTypeResolver::class)),
            routeBindingSchemaResolvers: $sorter->sort($this->partition($instances, RouteBindingSchemaResolver::class)),
            environmentDigestContributors: $sorter->sort($this->partition($instances, EnvironmentDigestContributor::class)),
        );
    }

    /**
     * @param  class-string|object|Closure  $registration
     * @return list<object>
     */
    private function expand(string|object $registration, Container $container): array
    {
        if ($registration instanceof Closure) {
            $registrar = new Registrar;
            $registration($registrar);

            $out = [];
            foreach ($registrar->all() as $entry) {
                $out[] = is_string($entry) ? $this->make($container, $entry) : $entry;
            }

            return $out;
        }

        return [is_string($registration) ? $this->make($container, $registration) : $registration];
    }

    /**
     * @param  class-string  $class
     */
    private function make(Container $container, string $class): object
    {
        /** @var object $resolved */
        $resolved = $container->make($class);

        return $resolved;
    }

    /**
     * @template T of object
     *
     * @param  list<object>  $instances
     * @param  class-string<T>  $contract
     * @return list<T>
     */
    private function partition(array $instances, string $contract): array
    {
        $out = [];
        foreach ($instances as $instance) {
            if ($instance instanceof $contract) {
                $out[] = $instance;
            }
        }

        return $out;
    }
}
