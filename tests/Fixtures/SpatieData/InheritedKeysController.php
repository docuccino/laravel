<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Returns the two hierarchies whose keys are decided by a class attribute the leaf does not hold. */
final class InheritedKeysController
{
    public function inheritedProperty(): InheritedKeysData
    {
        return new InheritedKeysData('Ada');
    }

    public function inheritedMapper(): MappedDescendantData
    {
        return new MappedDescendantData('Ada');
    }
}
