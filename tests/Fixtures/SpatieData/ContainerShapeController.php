<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Type-hints {@see ContainerShapeData}, so its recovered array shapes reach the request recovery. */
final class ContainerShapeController
{
    public function store(ContainerShapeData $data): ContainerShapeData
    {
        return $data;
    }
}
