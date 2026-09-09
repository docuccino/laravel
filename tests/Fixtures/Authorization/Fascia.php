<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\FasciaPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Annotated TWICE with an attribute that may not be repeated — a duplication PHP accepts in the source
 * and refuses only when something instantiates the attribute, which is what resolving a policy does. So
 * this is a model whose policy resolution raises, and the raise comes from the Gate's own step rather
 * than from anything a test overrode.
 *
 * The conventional `Policies\FasciaPolicy` is there and answers `viewAny`, so a resolution that did NOT
 * raise would have found it: silence about this model can only be the raise.
 *
 * @property int $id
 */
#[UsePolicy(FasciaPolicy::class)]
#[UsePolicy(FasciaPolicy::class)]
final class Fascia extends Model {}
