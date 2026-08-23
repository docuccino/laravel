<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;
use Docuccino\Core\Extensions\Contracts\ViewerSpecVersion;

/**
 * The Redoc {@see Viewer} (`viewer.driver => 'redoc'`): a three-panel reference page for a document.
 * Same asset policy as the default — the standalone bundle ships with the package and is served from
 * the viewer's own gated asset route, with `viewer.cdn => true` the opt-in to jsDelivr instead.
 *
 * Redoc's community build renders the reference only: there is no try-it-out console, which is the
 * one capability a reader loses by choosing it over Scalar. The bundled 2.x parses a 3.2 document by
 * aliasing it to 3.1, dropping 3.2-only semantics on the floor — so this driver declares 3.1, the
 * version it implements, and the spec endpoint serves that.
 */
final class RedocViewer implements Viewer, ViewerAssets, ViewerSpecVersion
{
    private const string CDN_SRC = 'https://cdn.jsdelivr.net/npm/redoc@2/bundles/redoc.standalone.js';

    public function name(): string
    {
        return 'redoc';
    }

    public function specVersion(): string
    {
        return '3.1';
    }

    public function render(ViewerContext $context): string
    {
        $page = new ViewerPage($context);

        return $page->render([
            sprintf('<redoc spec-url="%s"></redoc>', $page->specUrl()),
            sprintf('<script src="%s"></script>', $page->scriptSrc('redoc', self::CDN_SRC)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function assets(): array
    {
        return ['redoc' => dirname(__DIR__, 2).'/resources/js/redoc.standalone.js'];
    }
}
