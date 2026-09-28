<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * Which returns render a payload by the payload's own rules. The router sends a returned resource or Data
 * object through its `toResponse()`, so the bare object and a `JsonResponse` whose status is the payload's
 * own ({@see PayloadStatusT}) are the same response — with or without a media type a header stamped on it;
 * one whose status the code stated, or could not read, is the code's response.
 */
it('answers the payload only for a return whose status is the payload\'s to decide', function (DType $type, ?DType $expected): void {
    expect(FrameworkClasses::selfRendered($type))->toEqual($expected);
})->with(function (): array {
    $resource = new ClassT('App\\Http\\Resources\\WidgetResource');
    $json = static fn (DType ...$args): ClassT => new ClassT(FrameworkClasses::JSON_RESPONSE, $args);

    return [
        'a bare object' => [$resource, $resource],
        'a bare array shape' => [new ArrayShapeT([]), new ArrayShapeT([])],
        'rendered, the payload\'s status' => [$json($resource, new PayloadStatusT), $resource],
        'rendered, the payload\'s status, relabelled' => [$json($resource, new PayloadStatusT, new LiteralT('application/vnd.api+json')), $resource],
        // The marker is a status, never an absence: a payload with no status slot is not one rendering itself.
        'a payload with no status slot' => [$json($resource), null],
        'rendered, a stated status' => [$json($resource, new LiteralT(201)), null],
        'rendered, an unreadable status' => [$json($resource, new UnknownT('status not folded')), null],
        'no body at all (noContent)' => [$json(new VoidT, new LiteralT(204)), null],
        'a bare JsonResponse' => [$json(), null],
        'another framework response' => [new ClassT(FrameworkClasses::REDIRECT_RESPONSE), null],
    ];
});
