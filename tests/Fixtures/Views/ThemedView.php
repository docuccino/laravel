<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Views;

use Illuminate\View\View;

/**
 * An app's own view subclass — the shape a custom view factory hands back. It is the case the class list
 * alone would miss, so it has to be loadable in-process for the subclass clause to be exercised for real.
 */
final class ThemedView extends View {}
