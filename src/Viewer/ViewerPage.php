<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;

/**
 * The HTML skeleton every bundled driver renders into, plus the three URLs they all need: the
 * document's `.json` endpoint, the driver's script, and the page title.
 *
 * Composition rather than a base class on purpose — a shared parent would publish a protected
 * surface we would have to freeze at v1 to save a dozen lines, and a third-party driver implements
 * {@see Viewer} directly anyway.
 *
 * @internal
 */
final readonly class ViewerPage
{
    public function __construct(private ViewerContext $context) {}

    /** The document's `.json` endpoint, escaped for an HTML attribute. */
    public function specUrl(): string
    {
        return $this->attr(url($this->base().'.json'));
    }

    /**
     * Where the driver's browser bundle comes from, escaped for an HTML attribute: its own gated
     * asset route, or $cdn when the document opted in with `viewer.cdn`.
     */
    public function scriptSrc(string $asset, string $cdn): string
    {
        return $this->attr(($this->context->config->viewer['cdn'] ?? false) === true
            ? $cdn
            : url($this->base().'/assets/'.$asset.'.js'));
    }

    /**
     * The whole page around a driver's body markup, one element per line.
     *
     * @param  list<string>  $body
     */
    public function render(array $body): string
    {
        $title = $this->attr($this->title());
        $markup = implode("\n    ", $body);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title}</title>
            </head>
            <body>
                {$markup}
            </body>
            </html>
            HTML;
    }

    private function base(): string
    {
        $route = $this->context->config->viewer['route'] ?? null;

        return rtrim(is_string($route) && $route !== '' ? $route : '/docs/'.$this->context->config->key, '/');
    }

    private function title(): string
    {
        $title = $this->context->config->info['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'API Documentation';
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
