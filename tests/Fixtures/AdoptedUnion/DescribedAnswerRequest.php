<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

use Docuccino\Attributes\BodyParameter;

/** The same rules, with the answer described on the request and given no type. */
#[BodyParameter(name: 'answer', description: 'The answer, in the shape its kind takes.')]
final class DescribedAnswerRequest extends AnswerRequest {}
