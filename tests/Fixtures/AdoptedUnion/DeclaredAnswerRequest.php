<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

use Docuccino\Attributes\BodyParameter;

/** The same rules, with the type they are built into declared on the request itself. */
#[BodyParameter(name: 'answer', type: Answer::class.'|null')]
final class DeclaredAnswerRequest extends AnswerRequest {}
