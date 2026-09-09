<?php

declare(strict_types=1);

// Laravel loads this file while it BOOTS, and Docuccino keeps only what boot and a viewer request
// need in it: the master switch, each document's viewer wiring, and the cache store. Everything that
// shapes a document — routes, info, servers, security, lint, the engine — is read from docuccino.yaml
// at the root of your project instead. Anything else left here is reported and ignored, never merged.

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
    | One entry per document, keyed as docuccino.yaml keys it, holding that
    | document's viewer. A document declared there and absent here simply has
    | no page to serve, which is what an export-only document is.
    |
    */

    'documents' => [

        'default' => [

            'viewer' => [
                'route' => '/docs/api', // null registers no runtime endpoints for this document
                'gate' => null,         // a Gate ability name; null means local environment only
                // Middleware for the viewer routes. Keep `throttle` — building a spec is expensive
                // enough to be worth protecting. If your `web` group resolves a domain or a tenant,
                // these domain-less routes cannot satisfy it and the viewer 404s: drop `web`, or add
                // your own domain middleware here.
                'middleware' => ['web', 'throttle:60,1'],
                // Where the served spec comes from: `generate` rebuilds on every request (fine locally
                // or behind a gate), `artifact` reads the document's export path, `cache` serves what
                // `docuccino:cache` warmed — prefer one of those two for an exposed viewer.
                'source' => 'generate', // generate | artifact | cache
                // Which viewer renders the page. 'scalar' (the default) has a try-it-out console;
                // 'redoc' is a reference-only three-panel layout. Both ship their script with the
                // package. Your own driver registers with Docuccino::extend() and is named here.
                // 'driver' => 'scalar',
                // 'cdn' => true, // load the driver's script from a CDN instead of the shipped asset
                // 'configuration' => [], // passed verbatim to Scalar's data-configuration (theme, layout, …)
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The Laravel cache store `docuccino:cache` warms and a viewer request reads
    | back. The fragment cache that speeds a BUILD up is configured in
    | docuccino.yaml, under `cache`, where the build can read it.
    |
    */

    'cache' => [
        'store' => null, // Laravel cache store backing `docuccino:cache` (null = default store)
    ],
];
