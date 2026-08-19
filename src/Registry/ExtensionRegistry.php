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
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Illuminate\Contracts\Container\Container;

/**
 * The late-bound extension registry (design §6). `extend()` only appends a class-string, an instance or
 * a {@see Registrar} closure; nothing is read until {@see resolve()} runs at BUILD time — or until
 * {@see viewers()} runs while serving a docs page — never at boot. There's no accessor that returns
 * the list earlier, so an early snapshot is impossible and a registration from any provider — or from
 * after the app finished booting — still lands. Config `extensions` merge in at resolve time too.
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
     * Every registration, config extension and built-in default, partitioned by contract and sorted.
     * Called once per build.
     *
     * @param  list<class-string|object>  $defaults  the built-in extensions
     * @param  list<class-string|object>  $configExtensions  from `config('docuccino.extensions')`
     */
    public function resolve(Container $container, array $defaults, array $configExtensions): ResolvedExtensions
    {
        $instances = $this->instances($container, $defaults, $configExtensions);
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
            routeBindingFieldSchemaResolvers: $sorter->sort($this->partition($instances, RouteBindingFieldSchemaResolver::class)),
            environmentDigestContributors: $sorter->sort($this->partition($instances, EnvironmentDigestContributor::class)),
            routeNoteCollectors: $sorter->sort($this->partition($instances, RouteNoteCollector::class)),
        );
    }

    /**
     * The registered {@see Viewer}s keyed by driver name — the same registrations, config entries and
     * built-in defaults `resolve()` reads, partitioned for the one consumer that asks at request time
     * rather than at build time.
     *
     * Not a second registration mechanism: `Docuccino::extend(MyViewer::class)` is still the only way
     * in. Defaults come first, so a registration naming an existing driver replaces it — which is how
     * an application re-skins `scalar` without also having to replace the routes.
     *
     * @param  list<class-string|object>  $defaults  the built-in drivers
     * @param  list<class-string|object>  $configExtensions  from `config('docuccino.extensions')`
     * @return array<string, Viewer>
     */
    public function viewers(Container $container, array $defaults, array $configExtensions): array
    {
        $viewers = [];

        foreach ($this->instances($container, $defaults, $configExtensions) as $instance) {
            if ($instance instanceof Viewer) {
                $viewers[$instance->name()] = $instance;
            }
        }

        return $viewers;
    }

    /**
     * Every registration as an instance, defaults first — the one order both consumers read, so a
     * registration cannot reach the build and miss the viewer lookup or the other way round.
     *
     * @param  list<class-string|object>  $defaults
     * @param  list<class-string|object>  $configExtensions
     * @return list<object>
     */
    private function instances(Container $container, array $defaults, array $configExtensions): array
    {
        $instances = [];

        foreach ([...$defaults, ...$this->registrations, ...$configExtensions] as $registration) {
            foreach ($this->expand($registration, $container) as $instance) {
                $instances[] = $instance;
            }
        }

        return $instances;
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
