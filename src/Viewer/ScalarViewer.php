<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;

/**
 * The bundled {@see Viewer}: renders a Scalar API-reference page for a document. The Scalar
 * standalone script is served LOCALLY from the viewer's asset route (no runtime CDN), unless the
 * document opts in with `viewer.cdn => true`. The page points Scalar at the document's `.json`
 * spec endpoint via `data-url`.
 */
final class ScalarViewer implements Viewer
{
    private const CDN_SRC = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference';

    public function render(ViewerContext $context): string
    {
        $viewer = $context->config->viewer;

        $base = rtrim($this->route($context), '/');
        $specUrl = url($base.'.json');
        $assetSrc = ($viewer['cdn'] ?? false) === true ? self::CDN_SRC : url($base.'/assets/scalar.js');

        $title = htmlspecialchars($this->title($context), ENT_QUOTES);
        $specAttr = htmlspecialchars($specUrl, ENT_QUOTES);
        $assetAttr = htmlspecialchars($assetSrc, ENT_QUOTES);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title}</title>
            </head>
            <body>
                <script id="api-reference" data-url="{$specAttr}"></script>
                <script src="{$assetAttr}"></script>
            </body>
            </html>
            HTML;
    }

    private function route(ViewerContext $context): string
    {
        $viewer = $context->config->viewer;
        $route = $viewer['route'] ?? null;

        return is_string($route) && $route !== '' ? $route : '/docs/'.$context->config->key;
    }

    private function title(ViewerContext $context): string
    {
        $title = $context->config->info['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'API Documentation';
    }
}
