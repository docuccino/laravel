<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

/**
 * The {@see AssertsApiContract} assertions as an object, for a test that cannot take the trait — a
 * Pest file in a suite whose `uses()` you do not control, or a base test case you do not own.
 *
 * `ApiContract::assertions()` hands you one. It is the same code either way: the trait is where the
 * assertions live, and this is a host for it.
 */
final class ContractAssertions
{
    use AssertsApiContract;
}
