<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Declared;

use Docuccino\Attributes\Webhook;

/** A webhook whose delivered body is a free-form map, said in the one word an author has for it. */
#[Webhook('declared.map', payload: 'object')]
final readonly class DeclaredMap {}
