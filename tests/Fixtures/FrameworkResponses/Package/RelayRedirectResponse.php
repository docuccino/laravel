<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package;

use Illuminate\Http\RedirectResponse;

/** A package's redirect, sent on to wherever it is pointed. */
class RelayRedirectResponse extends RedirectResponse {}
