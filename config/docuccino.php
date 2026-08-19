<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The master switch. When false the artisan commands refuse to run and no
    | viewer routes are registered at all.
    |
    */

    'enabled' => env('DOCUCCINO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Each entry is an independent build with its own routes, info, security and
    | export target. Add more alongside `default` to emit a multi-document set —
    | they share route analysis, so a second document is cheap.
    |
    */

    'documents' => [

        'default' => [

            // The OpenAPI `info` object. Any other OAS info field (contact, license…) passes through.
            'info' => [
                'title' => 'API Documentation',
                'version' => '1.0.0',
                // 'description' => 'Markdown, inline.',
                // 'description' => ['file' => 'resources/docs/api/description.md'],
            ],

            // The OpenAPI `servers` array.
            'servers' => [
                // ['url' => 'https://api.example.com', 'description' => 'Production'],
            ],

            // Which routes belong to this document.
            'routes' => [
                'include' => ['api/*'],
                'exclude' => [],
                // A closure filter, applied after the wildcards above.
                'closure' => null, // fn (RouteDescriptor $route): bool => ...
                // Also document routes whose controller lives under vendor/. Off by default, matching
                // `php artisan route:list --except-vendor`; closures and your own controllers are
                // unaffected either way.
                'include_vendor' => false,
            ],

            'security' => [
                // Routes whose middleware matches this wildcard get the `default` requirement below.
                'auto_detect_middleware' => 'auth*',
                // Your own schemes, emitted as `components.securitySchemes`. Sanctum and Passport
                // contribute theirs automatically when installed.
                // 'schemes' => [
                //     'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                //     'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                // ],
                // The per-operation requirement applied to routes matched by auto_detect_middleware.
                // 'default' => [['bearer' => []]],
                // A requirement applied to the whole document.
                // 'document' => [['bearer' => []]],
            ],

            // 'default' documents the framework's own JSON error shapes, 'problem-details' the
            // RFC 9457 (application/problem+json) preset, 'none' emits no error responses.
            'error_responses' => 'default',
            // The bag form also picks how a 422 models its `errors`: 'map' (field to messages) is the
            // default, 'pointer-list' a list of {detail, pointer} objects.
            // 'error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'pointer-list'],

            'tags' => [
                // How an operation with no #[Group] is tagged: 'controller' (InvoiceController →
                // "Invoice", then through `map`) or 'none'. Closure routes are never auto-tagged.
                'default_strategy' => 'controller',
                // Raw tag => display tag. An exact match wins, else the first prefix the tag starts with.
                'map' => [],
                // A container-resolved TagMapper, replacing the prefix mapper over `map`.
                // 'mapper' => App\Docs\InvoiceTagMapper::class,
                // The OpenAPI top-level `tags`, sorted by weight then name. `parent` nests one entry
                // under another (it must name a tag defined here) and `kind` categorises it —
                // both OAS 3.2 only, so the 3.1 downlevel drops them with a warning.
                // 'definitions' => [
                //     ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav', 'weight' => 0],
                //     ['name' => 'Invoices', 'description' => 'Billing documents.', 'parent' => 'Billing'],
                // ],
            ],

            // Where #[Webhook] classes live: every annotated class found under here is published as a
            // webhook. Relative to the base path; absent means the document has none.
            // 'webhooks' => ['dir' => 'app/Webhooks'],

            // A markdown tree compiled into the document as pages and navigation.
            'content' => [
                'dir' => null, // e.g. 'resources/docs/api'
            ],

            // Response bodies your test suite recorded, published as examples. The build only ever
            // READS these committed files — nothing is executed and no database is touched; the
            // recording is written by your suite (ApiContract::record()). Absent means the document
            // publishes no recorded examples.
            // 'examples' => ['recordings' => 'docs/recordings'],

            // Where the contract-coverage recorder writes what your test suite exercised, and where
            // `docuccino:coverage` reads it back. Absent means storage/docuccino/coverage.
            // 'coverage' => ['log' => 'storage/docuccino/coverage'],

            // Overlay 1.0 documents applied to the finished build (globs, relative to the base path).
            'overlays' => [
                // 'resources/docs/overlays/*.yaml',
            ],

            // How inferred facts are expressed in the spec. Every default below is the shape most
            // codegen tools expect; change one only if your consumers prefer the alternative.
            'representation' => [
                'filters' => 'bracketed',       // bracketed: one filter[status] param | deepObject: a single `filter` object
                'lists' => 'comma',             // comma: ?sort=a,b | array: ?sort[]=a&sort[]=b
                'nullable' => 'type-array',     // type-array: type: [string, null] | anyof: a {type: null} branch
                'operation_id' => 'route-name', // route-name | controller-method (InvoiceController@store)
                // 'enums' => [
                //     'naming' => 'none',      // none | x-enumNames | x-enum-varnames (codegen name hints)
                //     'components' => true,    // true hoists each enum to a $ref'd component; false inlines it
                // ],
                // 'errors' => [
                //     'components' => true,    // true hoists an error body repeated across operations to one
                //                              // $ref'd components.responses entry; false inlines every copy
                // ],
            ],

            // The policy `docuccino:diff --enforce` holds a changeset to.
            'versioning' => 'none', // none | semver | date

            // Per-integration switches and knobs. Every integration is on as soon as its package is
            // installed — except `permission`, which is opt-in because documenting role and permission
            // names publishes your app's internal authorization taxonomy. An integration that is
            // installed but disabled emits one `integration.disabled` info diagnostic per build, so the
            // switch is always discoverable from the output.
            'integrations' => [
                // 'api_resources' => [
                //     'enabled' => true,
                //     'wrap' => true,   // false never wraps (matches a global withoutWrapping()); true
                //                       // wraps in 'data'; a string forces that key; omit to follow
                //                       // each resource's own $wrap.
                // ],
                // 'eloquent' => ['enabled' => true],
                // 'rate_limit' => ['enabled' => true],
                // 'spatie_data' => ['enabled' => true],
                // 'query_builder' => [
                //     'enabled' => true,
                //     'pagination_terminals' => ['paginateList'], // your own paginating method names
                // ],
                // 'json_api_paginate' => ['enabled' => true],
                // 'timacdonald_json_api' => ['enabled' => true],
                // 'laravel_actions' => ['enabled' => true],
                // 'sanctum' => [
                //     'enabled' => true,
                //     'modes' => ['token', 'stateful'], // which schemes to expose (default: both)
                //     'cookie' => 'myapp_session',      // stateful cookie name (default: session.cookie)
                // ],
                // 'passport' => [
                //     'enabled' => true,
                //     'url' => 'https://auth.example.com', // oauth2 flow base URL (default: app.url)
                // ],
                // 'permission' => ['enabled' => true], // opt in to x-permissions + the requirement lines
            ],

            // Where `docuccino:export` writes, and what the viewer's `artifact` source reads back.
            'export' => [
                'path' => 'docs/openapi.json',
                // A list of targets REPLACES `path`: one build, one artifact per entry. One target per
                // format — openapi-3.2, openapi-3.1, openapi-3.0, uir, postman — and the extension
                // picks the serialisation (.yaml/.yml emit YAML).
                // 'targets' => [
                //     ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
                //     ['format' => 'openapi-3.1', 'path' => 'docs/openapi-3.1.yaml'],
                // ],
                // The member #[Mock] hints are published under in the OpenAPI artifacts. Absent keeps
                // them out, so an export is pure OpenAPI; the UIR carries them either way.
                // 'mock_faker_key' => 'x-faker',
            ],

            'viewer' => [
                'route' => '/docs/api', // null registers no runtime endpoints for this document
                'gate' => null,         // a Gate ability name; null means local environment only
                // Middleware for the viewer routes. Keep `throttle` — building a spec is expensive
                // enough to be worth protecting. If your `web` group resolves a domain or a tenant,
                // these domain-less routes cannot satisfy it and the viewer 404s: drop `web`, or add
                // your own domain middleware here.
                'middleware' => ['web', 'throttle:60,1'],
                // Where the served spec comes from: `generate` rebuilds on every request (fine locally
                // or behind a gate), `artifact` reads export.path, `cache` serves what
                // `docuccino:cache` warmed — prefer one of those two for an exposed viewer.
                'source' => 'generate',
                // Which viewer renders the page. 'scalar' (the default) has a try-it-out console;
                // 'redoc' is a reference-only three-panel layout. Both ship their script with the
                // package. Your own driver registers with Docuccino::extend() and is named here.
                // 'driver' => 'scalar',
                // 'cdn' => true, // load the driver's script from a CDN instead of the shipped asset
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | Your own extension class-strings, resolved from the container at build time
    | and merged with anything registered through Docuccino::extend().
    |
    */

    'extensions' => [
        // App\Docs\InvoiceTotalsExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lint
    |--------------------------------------------------------------------------
    |
    | Document lint rules. They only ever raise diagnostics — nothing here can
    | change the emitted document.
    |
    */

    'lint' => [
        // Warns when a schema property name looks sensitive (password/token/secret/api_key…), and
        // when a published example, const, enum or default value looks like a credential.
        'leakage' => [
            'enabled' => true,
            // Property names or JSON pointers to accept, e.g.
            // ['reset_token', '#/components/schemas/Invoice/properties/status']. A name silences this
            // lint only; the response recorder redacts by name regardless and honours a pointer alone.
            'allow' => [],
            // Extra heuristics, merged over the built-in table (built-in tokens keep their label).
            // Key = a token matched anywhere inside a property name, value = the label in the message.
            // 'patterns' => ['sortcode' => 'a bank sort code', 'iban' => 'an IBAN'],
        ],
        // Warns on an operation that publishes neither a summary nor a description. Off by default:
        // on an application that documents nothing it fires once per operation.
        'descriptions' => [
            'enabled' => false,
            // Operation signatures or operationIds to accept, e.g. ['GET /api/ping'].
            'allow' => [],
        ],
        // Warns on an operationId a generated client cannot name a method after — empty, leading with
        // a digit, or outside letters, digits and . - _ @. Duplicates are a separate diagnostic
        // (route.duplicate-operation-id). Nothing Docuccino mints can trip it.
        'operation_ids' => [
            'enabled' => true,
            // Operation signatures or operationIds to accept, e.g. ['GET /api/ping'].
            'allow' => [],
        ],
        // Warns on a tag operations carry that tags.definitions never declares. Silent unless the
        // document declares tags at all — and off besides, because declaring a few nav parents while
        // the rest derive from controllers is a deliberate shape, not a hole.
        'tags' => [
            'enabled' => false,
            // Tag names to accept, e.g. ['Internal'].
            'allow' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    |
    | Codes you have read and accepted. An accepted diagnostic still prints,
    | marked `accepted`, and stops counting towards `--fail-on` — so a stricter
    | gate can go on before the last report is fixed. An error is never
    | accepted, and an entry nothing reports warns so the list cannot rot.
    |
    */

    'diagnostics' => [
        'accept' => [
            // 'eloquent.no-columns',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inference engine
    |--------------------------------------------------------------------------
    |
    | Which engine backs type inference. A boot failure always degrades to no
    | inference rather than failing the build, so docblock- and attribute-driven
    | documentation keeps working.
    |
    */

    'engine' => [
        // 'in-process' runs PHPStan; 'null' skips inference entirely. Inference needs the dev-only
        // docuccino/inference-phpstan package — without it 'in-process' warns and falls back.
        // Anything else warns and runs in-process rather than failing the build.
        'mode' => env('DOCUCCINO_ENGINE', 'in-process'),
        // PHP memory limit for inference, which runs PHPStan inside the calling process. Applied on
        // console builds only, and only ever RAISES — an already-higher (or unlimited) process is left
        // alone, and `-1` isn't accepted here. `--memory-limit` on the build commands overrides it.
        // 'memory_limit' => '2G',
        // Directories the engine descends into for interprocedural analysis (throw classification,
        // inline `Validator::make(...)` rules). Every PSR-4 source root in your composer.json — a
        // modular `Modules\…` root included — is already loaded so helpers there resolve; widen this
        // only to broaden that descent. Vendor code is never analysed.
        'project_paths' => ['app'],
        // Your own PHPStan config file, included by the one the engine writes, so the extensions and
        // stubs you already maintain shape your documentation too. Relative to the base path; a file
        // that isn't there warns and inference runs without it.
        // 'neon' => 'phpstan.neon',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route failures & caching
    |--------------------------------------------------------------------------
    */

    // What a route whose analysis fails leaves behind: 'skeleton' keeps the path with a diagnostic,
    // 'omit' drops it from the document.
    'on_route_error' => 'skeleton',

    'cache' => [
        // The per-operation fragment cache: incremental rebuilds. `docuccino:watch` turns it on for
        // the builds it drives by setting DOCUCCINO_FRAGMENT_CACHE, which is why this reads the env.
        'enabled' => env('DOCUCCINO_FRAGMENT_CACHE', false),
        'store' => null,    // Laravel cache store backing `docuccino:cache` (null = default store)
        // 'path' => null,  // fragment directory (default: storage_path('docuccino/fragments'))
    ],
];
