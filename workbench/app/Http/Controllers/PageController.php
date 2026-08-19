<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The HTML corner of a Laravel app: an action that renders a Blade template rather than answering JSON.
 * Only ever analysed, never dispatched, so the template it names does not have to exist.
 */
final class PageController
{
    /** The stock "render a page" signature. */
    public function dashboard(): View
    {
        return view('pages.dashboard', ['title' => 'Dashboard']);
    }
}
