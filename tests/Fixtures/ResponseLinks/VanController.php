<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\FleetVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\MutableVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\OverridingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\PaintedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;
use Spatie\LaravelData\Data;

/**
 * A van's routes, each answering with a Data object built from the van in one of the ways an action
 * builds one; and five whose Data object is not a plain copy of it. Never dispatched.
 */
final class VanController
{
    public function store(Van $van): VanData
    {
        return VanData::from($van);
    }

    public function show(Van $van): VanData
    {
        return new VanData(id: $van->id, plate: $van->plate, colour: $van->colour);
    }

    public function byPlate(Van $van): VanData
    {
        return VanData::from(['id' => $van->id, 'plate' => $van->plate, 'colour' => $van->colour]);
    }

    public function enlist(Van $van): FleetVanData
    {
        return FleetVanData::from($van);
    }

    public function enlistInOrder(Van $van): FleetVanData
    {
        return new FleetVanData($van->getKey(), $van->fleet_number, $van->plate);
    }

    public function unwrapped(Van $van): VanData
    {
        $data = VanData::from($van);

        return $data->withoutWrapping();
    }

    public function painted(Van $van): PaintedVanData
    {
        return PaintedVanData::from($van);
    }

    public function retitled(Van $van): MutableVanData
    {
        $copy = MutableVanData::from($van);
        $copy->plate = strtoupper($copy->plate);

        return $copy;
    }

    public function demonstrated(Van $van): OverridingVanData
    {
        return OverridingVanData::from($van);
    }

    public function described(Van $van): VanData
    {
        return $this->withMeta(VanData::from($van));
    }

    /**
     * @template T of Data
     *
     * @param  T  $meta
     * @return T
     */
    private function withMeta(Data $meta): Data
    {
        return $meta->additional(['plate' => 'n/a']);
    }
}
