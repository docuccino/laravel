<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

final readonly class LastAnswer
{
    public function __construct(public ?Answer $answer) {}
}
