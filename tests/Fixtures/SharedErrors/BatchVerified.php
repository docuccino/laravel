<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/**
 * Says of an origin that the identity behind it was checked. A marker: the fact IS the type, so it
 * declares no members of its own — which is what makes `BatchOrigin&BatchVerified` the ordinary way to
 * type "an origin, and a verified one".
 */
interface BatchVerified {}
