<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A parcel's routes: made, read, changed, removed, read by tracking code, made again answering with the
 * model itself rather than a resource, and read through a `Route::model()` binding; and a pallet's,
 * looked up by the key its model names. Never dispatched.
 */
final class ParcelController
{
    public function store(): ParcelResource
    {
        return new ParcelResource(Parcel::create([]));
    }

    public function show(Parcel $parcel): ParcelResource
    {
        return new ParcelResource($parcel);
    }

    public function update(Parcel $parcel): ParcelResource
    {
        return new ParcelResource($parcel);
    }

    public function destroy(Parcel $parcel): void {}

    public function track(Parcel $parcel): ParcelResource
    {
        return new ParcelResource($parcel);
    }

    public function storeRaw(): Parcel
    {
        return Parcel::create([]);
    }

    /** Bound by a binder the application registered, so what the segment holds is the binder's business. */
    public function consigned(Parcel $consignment): ParcelResource
    {
        return new ParcelResource($consignment);
    }

    /** Bound with `Route::model()`, so the signature names no type. */
    public function shipped(mixed $shipment): ParcelResource
    {
        return new ParcelResource($shipment);
    }

    public function storePallet(): PalletResource
    {
        return new PalletResource(Pallet::create([]));
    }

    public function showPallet(Pallet $pallet): PalletResource
    {
        return new PalletResource($pallet);
    }
}
