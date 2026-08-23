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
    // Pinned to the shipped bundle's major, like the Redoc driver's `redoc@2` — an unpinned CDN can
    // float to a build whose behavior nothing here has verified.
    private const string CDN_SRC = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1';

    public function name(): string
    {
        return 'scalar';
    }

    public function render(ViewerContext $context): string
    {
        $page = new ViewerPage($context);

        // `viewer.configuration` rides Scalar's own data-configuration attribute verbatim — theme,
        // layout, hideModels and friends are Scalar's contract, not one restated here.
        $configuration = $context->config->viewer['configuration'] ?? null;
        $attribute = is_array($configuration) && $configuration !== []
            ? sprintf(' data-configuration="%s"', $page->attr((string) json_encode($configuration)))
            : '';

        return $page->render([
            sprintf('<script id="api-reference" data-url="%s"%s></script>', $page->specUrl(), $attribute),
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
