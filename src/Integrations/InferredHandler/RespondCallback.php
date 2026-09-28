<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Inference\CallableRef;

/**
 * The callback an application hands `$exceptions->respond(…)`, found on the booted exception handler: where
 * it is written, and the names of the three parameters Laravel passes it by POSITION — the rendered
 * response, the exception and the request — each null where the callback declares fewer.
 *
 * Located as every handler callable is ({@see LocatedCallable}).
 */
final readonly class RespondCallback
{
    public function __construct(
        public LocatedCallable $at,
        public ?string $responseParameter,
        public ?string $exceptionParameter,
        public ?string $requestParameter,
    ) {}

    /**
     * The callback analysed for one thrown type: every return reachable with the exception parameter
     * narrowed to what it is handed for that throw ({@see ReceivedException}), or not narrowed where that
     * is not knowable. A callback that never names the exception is asked once.
     */
    public function ref(string $thrownFqcn): CallableRef
    {
        $narrowType = $this->exceptionParameter === null ? null : ReceivedException::byRespondCallback($thrownFqcn);

        return $this->at->ref($this->exceptionParameter, $narrowType, narrowToEvery: true);
    }
}
