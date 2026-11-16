<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A parcel's scans: taken under the parcel, read and corrected there, read on their own, and the parcel's
 * latest — which may be any scan at all as far as the code says. Never dispatched.
 */
final class ScanController
{
    public function store(Parcel $parcel): ScanResource
    {
        return new ScanResource(Scan::create(['parcel_id' => $parcel->id]));
    }

    public function show(Parcel $parcel, Scan $scan): ScanResource
    {
        return new ScanResource($scan);
    }

    public function update(Parcel $parcel, Scan $scan): ScanResource
    {
        return new ScanResource($scan);
    }

    public function latest(Parcel $parcel): ScanResource
    {
        return new ScanResource(Scan::query()->latest()->firstOrFail());
    }

    public function find(Scan $scan): ScanResource
    {
        return new ScanResource($scan);
    }
}
