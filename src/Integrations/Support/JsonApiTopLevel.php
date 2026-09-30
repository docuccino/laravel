<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\ApiResources\ResourceStaticsDigestContributor;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\ServerImplementationDigestContributor;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use Illuminate\Container\Container;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * The members a JSON:API document carries beside `data`, which both JSON:API families add in their own
 * `with()`: `included` whenever a requested relationship was loaded, and the `jsonapi` object the
 * application configures at boot — read here from that same boot state, which the environment digest
 * keys ({@see ResourceStaticsDigestContributor},
 * {@see ServerImplementationDigestContributor}).
 *
 * Only a class whose `with()` is its family's own is answered; one overriding it sends what it returns.
 */
final class JsonApiTopLevel
{
    private const IMPLEMENTATION = "The server's JSON:API implementation.";

    /**
     * What an `included` entry is sent as: any resource object, since which ones depends on the request.
     *
     * @var array<string, mixed>
     */
    private const RESOURCE_OBJECT = [
        'description' => 'A JSON:API resource object.',
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'attributes' => ['type' => 'object'],
            'relationships' => ['type' => 'object'],
            'links' => ['type' => 'object'],
            'meta' => ['type' => 'object'],
        ],
        'required' => ['id', 'type'],
    ];

    /**
     * The members `$fqcn`'s family `with()` adds, or null when `$fqcn` declares a `with()` of its own.
     *
     * @return array{properties: array<string, mixed>, required: list<string>}|null
     */
    public static function members(string $fqcn, SchemaContext $context): ?array
    {
        $declaring = self::declaring($fqcn, 'with');
        $jsonapi = match ($declaring) {
            // A single resource reads `static::`, its collection the base class — they part where a
            // resource redeclares the static.
            ResourceReflector::JSON_API_RESOURCE => self::configured($fqcn),
            ResourceReflector::JSON_API_COLLECTION => self::configured(ResourceReflector::JSON_API_RESOURCE),
            TimacdonaldResourceReflector::JSON_API_RESOURCE, TimacdonaldResourceReflector::JSON_API_COLLECTION => self::serverImplementation(),
            default => false,
        };

        if ($jsonapi === false) {
            return null;
        }

        // An included timacdonald resource is sent as the base sends one, which is not always its resource
        // object ({@see TimacdonaldResourceReflector::sentOtherwise()}).
        $timacdonald = in_array($declaring, [TimacdonaldResourceReflector::JSON_API_RESOURCE, TimacdonaldResourceReflector::JSON_API_COLLECTION], true);
        $members = ['properties' => ['included' => [
            'description' => 'Resource objects related to the primary data, sent as a compound document.',
            'type' => 'array',
            'items' => $timacdonald && TimacdonaldResourceReflector::sentOtherwise(TimacdonaldResourceReflector::JSON_API_RESOURCE) !== null
                ? ['type' => 'object']
                : $context->reference('JsonApiResourceObject', self::RESOURCE_OBJECT),
        ]], 'required' => []];
        if ($jsonapi !== null) {
            $members['properties']['jsonapi'] = $jsonapi['schema'];
            if ($jsonapi['always']) {
                $members['required'][] = 'jsonapi';
            }
        }

        return $members;
    }

    /**
     * The class `$fqcn`'s `$method` is declared by — which family's behaviour it has, since a JSON:API
     * family is told apart by the methods it inherits rather than by what it collects.
     */
    public static function declaring(string $fqcn, string $method): ?string
    {
        if (! class_exists($fqcn) || ! method_exists($fqcn, $method)) {
            return null;
        }

        return (new ReflectionMethod($fqcn, $method))->getDeclaringClass()->getName();
    }

    /**
     * The `jsonapi` object `configure()` left on `$fqcn`'s static — sent on every document while set, and
     * never while it is empty.
     *
     * @return array{schema: array<string, mixed>, always: bool}|null
     */
    private static function configured(string $fqcn): ?array
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        try {
            $information = (new ReflectionProperty($fqcn, 'jsonApiInformation'))->getValue();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($information) || $information === []) {
            return null;
        }

        $properties = $required = [];
        foreach ($information as $key => $value) {
            $properties[$key] = self::valueSchema($value);
            $required[] = (string) $key;
        }

        return ['schema' => ['description' => self::IMPLEMENTATION, 'type' => 'object', 'properties' => $properties, 'required' => $required], 'always' => true];
    }

    /**
     * timacdonald's `ServerImplementation`, sent wherever the bound callback returns one — which only the
     * request decides, so it is published without claiming it is always there.
     *
     * @return array{schema: array<string, mixed>, always: bool}|null
     */
    private static function serverImplementation(): ?array
    {
        if (! Container::getInstance()->bound(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER)) {
            return null;
        }

        return ['schema' => [
            'description' => self::IMPLEMENTATION,
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'string'],
                'meta' => ['type' => 'object'],
            ],
            'required' => ['version'],
        ], 'always' => false];
    }

    /**
     * The JSON type a configured value is sent as. The value itself is the application's, so only its
     * type is published.
     *
     * @return array<string, mixed>
     */
    private static function valueSchema(mixed $value): array
    {
        if (! is_array($value)) {
            return match (true) {
                is_string($value) => ['type' => 'string'],
                is_int($value) => ['type' => 'integer'],
                is_float($value) => ['type' => 'number'],
                is_bool($value) => ['type' => 'boolean'],
                default => [],
            };
        }

        if (! array_is_list($value)) {
            return ['type' => 'object'];
        }

        $strings = array_filter($value, is_string(...)) === $value;

        return $strings ? ['type' => 'array', 'items' => ['type' => 'string']] : ['type' => 'array'];
    }
}
