<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Returns the two Data classes whose root envelopes have to reach an emitted document. */
final class RootWrapController
{
    public function show(): NestedTransformDisabledData
    {
        return new NestedTransformDisabledData(1, new AuthorData('a', 'a@example.com'));
    }

    public function problem(): HelperContextProblemData
    {
        return new HelperContextProblemData('about:blank', 404);
    }
}
