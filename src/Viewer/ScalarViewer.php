<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;

/**
 * The default {@see Viewer} (`viewer.driver => 'scalar'`): a Scalar API-reference page for a
 * document. The Scalar standalone script is served LOCALLY from the viewer's asset route (no runtime
 * CDN), unless the document opts in with `viewer.cdn => true`. The page points Scalar at the
 * document's `.json` spec endpoint via `data-url`.
 */
final class ScalarViewer implements Viewer, ViewerAssets
{
    private const string CDN_SRC = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference';

    public function name(): string
    {
        return 'scalar';
    }

    public function render(ViewerContext $context): string
    {
        $page = new ViewerPage($context);

        return $page->render([
            sprintf('<script id="api-reference" data-url="%s"></script>', $page->specUrl()),
            sprintf('<script src="%s"></script>', $page->scriptSrc('scalar', self::CDN_SRC)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function assets(): array
    {
        return ['scalar' => dirname(__DIR__, 2).'/resources/js/scalar.standalone.js'];
    }
}
