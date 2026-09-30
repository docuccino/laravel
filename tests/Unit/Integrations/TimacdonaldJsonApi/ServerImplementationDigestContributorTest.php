<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\ServerImplementationDigestContributor;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use TiMacDonald\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\ServerImplementation;

/*
 * Binding a server implementation at boot adds `jsonapi` to every timacdonald document, and the binding
 * is container state no file records — so whether one is bound keys the cache.
 */
afterEach(function (): void {
    app()->offsetUnset(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER);
});

it('reads the binding under the key the package binds it under', function (): void {
    JsonApiResource::resolveServerImplementationUsing(static fn (): ServerImplementation => new ServerImplementation('1.0'));

    expect(app()->bound(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER))->toBeTrue();
});

it('digests whether a server implementation is bound', function (): void {
    $unbound = (new ServerImplementationDigestContributor)->digest();
    JsonApiResource::resolveServerImplementationUsing(static fn (): ServerImplementation => new ServerImplementation('1.0'));

    expect($unbound)->toBe("server-implementation\0")
        ->and((new ServerImplementationDigestContributor)->digest())->toBe("server-implementation\0bound");
});
