<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Rfc9457;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/** An RFC 9457 problem that sends itself as `application/problem+json`. */
final readonly class Problem implements Responsable
{
    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public int $status,
        public string $title,
        public string $detail,
        public string $type = 'about:blank',
        public array $extensions = [],
    ) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $request->getRequestUri(),
            ...$this->extensions,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
