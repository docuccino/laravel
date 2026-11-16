<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Docuccino\Attributes\ResponseLink;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\Channel;

/**
 * One declared link per action that the document cannot honour, and one it can. Never dispatched.
 */
final class MisdeclaredLinkController
{
    /** @return array{parcel: int} */
    #[ResponseLink('parcels.missing')]
    public function unknownOperation(): array
    {
        return ['parcel' => 1];
    }

    /** @return array{parcel: int} */
    #[ResponseLink('GET /api/parcels/{parcel}', ['parcel' => '$response.body#/parcel'], status: 201)]
    public function unknownStatus(): array
    {
        return ['parcel' => 1];
    }

    /** @return array{parcel: int} */
    #[ResponseLink('GET /api/parcels/{parcel}', ['parcelId' => '$response.body#/parcel'])]
    public function undeclaredParameter(): array
    {
        return ['parcel' => 1];
    }

    /** @return array{parcel: int} */
    #[ResponseLink('GET /api/parcels/{parcel}', ['parcel' => '$response.body#/parcel_id'])]
    public function undocumentedPointer(): array
    {
        return ['parcel' => 1];
    }

    /** @return array{parcel: int} */
    #[ResponseLink('GET /api/parcels/{parcel}', ['parcel' => Channel::Web])]
    public function unreadable(): array
    {
        return ['parcel' => 1];
    }

    /** @return array{parcel: int} */
    #[ResponseLink('GET /api/parcels/{parcel}', ['path.parcel' => '$response.body#/parcel'])]
    public function honoured(): array
    {
        return ['parcel' => 1];
    }
}
