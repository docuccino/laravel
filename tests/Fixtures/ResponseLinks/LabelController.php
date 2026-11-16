<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Docuccino\Attributes\ResponseLink;

/**
 * Prints a label for a parcel and answers with a plain array naming it — a body no model or resource
 * shapes, so nothing in the code ties its members to a column, and the link to the parcel is declared.
 * Never dispatched.
 */
final class LabelController
{
    /**
     * @return array{parcel: int, printer: string}
     */
    #[ResponseLink('GET /api/parcels/{parcel}', ['parcel' => '$response.body#/parcel'], name: 'LabelledParcel', description: 'The parcel the label is for.')]
    public function store(): array
    {
        return ['parcel' => 1, 'printer' => 'dock-3'];
    }
}
