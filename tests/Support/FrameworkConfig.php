<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

/**
 * A `config/docuccino.php` of the shape the last release shipped — the corpus the split is read
 * against, since what an unmigrated application actually holds is the question every guard here asks.
 */
final class FrameworkConfig
{
    /**
     * A populated `config/docuccino.php` of the shape the last release shipped — every build setting set
     * to something OTHER than its default, so a value that failed to travel shows up as a value and not
     * as a missing key.
     *
     * `documents.public` carries a viewer and nothing else, which is the shape that proves the split is
     * read one level into a document bag: everything in that bag belongs to the framework, and naming
     * any of it would send its author to delete their own viewer.
     *
     * @return array<string, mixed>
     */
    public static function populated(): array
    {
        return [
            'enabled' => true,
            'documents' => [
                'default' => [
                    'viewer' => ['route' => '/docs/api', 'gate' => 'view-docs', 'source' => 'artifact'],
                    'info' => ['title' => 'Billing API', 'version' => '3.1.4'],
                    'servers' => [['url' => 'https://api.example.com', 'description' => 'Production']],
                    'routes' => [
                        'include' => ['api/v2/*', 'api/v3/*'],
                        'exclude' => ['api/v2/internal/*'],
                        'closure' => null,
                        'include_vendor' => true,
                    ],
                    'security' => [
                        'auto_detect_middleware' => 'auth:sanctum*',
                        'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                        'default' => [['bearer' => []]],
                    ],
                    'error_responses' => 'none',
                    'tags' => ['default_strategy' => 'none', 'map' => ['Invoice' => 'Billing']],
                    'content' => ['dir' => 'resources/docs/api'],
                    'overlays' => ['resources/docs/overlays/*.yaml'],
                    'representation' => [
                        'filters' => 'deepObject',
                        'nullable' => 'anyof',
                        'operation_id' => 'controller-method',
                        'lists' => 'comma',
                    ],
                    'versioning' => 'semver',
                    'integrations' => ['permission' => ['enabled' => true], 'eloquent' => ['enabled' => false]],
                    'export' => ['path' => 'docs/billing.json'],
                ],
                'public' => [
                    'viewer' => ['route' => '/docs/public', 'gate' => null],
                ],
            ],
            'extensions' => ['App\Docs\InvoiceTotalsExtension'],
            'lint' => [
                'leakage' => ['enabled' => true, 'allow' => ['reset_token']],
                'descriptions' => ['enabled' => true, 'allow' => []],
            ],
            'diagnostics' => ['accept' => ['eloquent.no-columns']],
            'engine' => [
                'mode' => 'in-process',
                'project_paths' => ['app', 'modules'],
                'neon' => 'phpstan.neon',
            ],
            'on_route_error' => 'omit',
            'cache' => ['enabled' => true, 'store' => 'redis', 'path' => 'storage/docs/fragments'],
        ];
    }
}
