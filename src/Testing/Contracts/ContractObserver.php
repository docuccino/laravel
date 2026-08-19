<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing\Contracts;

use Docuccino\Laravel\Testing\ObservedExchange;

/**
 * Something that wants to see every exchange the contract assertions matched.
 *
 * This is the extension point, and it exists because the assertion path already holds exactly what
 * anything downstream needs — the request, the response, and the operation they were matched to. The
 * built-in coverage recorder is itself an observer; a response RECORDER (turning a real response into
 * a documented example) is the other obvious one, and neither has to reach inside the assertion to get
 * its inputs.
 *
 * Register one with `ApiContract::observe()`. Observers are notified once per assertion call, BEFORE
 * the assertion itself fails, so an observer sees failing exchanges too — and sees them twice if one
 * test asserts both halves. Keep the method cheap: it runs inside every HTTP test in the suite.
 */
interface ContractObserver
{
    public function observed(ObservedExchange $exchange): void;
}
