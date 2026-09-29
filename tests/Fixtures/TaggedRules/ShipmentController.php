<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

/** Takes the tagged-rules requests. */
final class ShipmentController
{
    public function store(StoreShipmentRequest $request): void {}

    public function storeMixed(StoreMixedShipmentRequest $request): void {}

    public function notify(StoreNoticeRequest $request): void {}

    public function storeTracked(StoreTrackedShipmentRequest $request): void {}

    public function notifyRouted(StoreRoutedNoticeRequest $request): void {}

    public function notifyDialled(StoreDialledNoticeRequest $request): void {}
}
