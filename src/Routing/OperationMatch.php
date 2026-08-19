<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

/**
 * One documented operation, named every way a reader might name it: the method and path a viewer or
 * an exported artifact shows them, the operation id a consumer quotes back, and — where the router
 * still knows — the route's name and action.
 *
 * @internal
 */
final readonly class OperationMatch
{
    public function __construct(
        public string $document,
        public string $path,
        public string $method,
        public ?string $operationId = null,
        public ?string $name = null,
        public ?string $action = null,
    ) {}

    /** The vocabulary every diagnostic already speaks: `POST /api/invoices`. */
    public function signature(): string
    {
        return strtoupper($this->method).' '.$this->path;
    }

    /** `InvoiceController@store` — how a developer says the action out loud. */
    public function shortAction(): ?string
    {
        if ($this->action === null || $this->action === '') {
            return null;
        }

        $parts = explode('@', $this->action, 2);
        $class = $parts[0];
        $position = strrpos($class, '\\');
        $short = $position === false ? $class : substr($class, $position + 1);

        return isset($parts[1]) ? $short.'@'.$parts[1] : $short;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document' => $this->document,
            'method' => $this->method,
            'path' => $this->path,
            'operationId' => $this->operationId,
            'routeName' => $this->name,
            'action' => $this->action,
        ];
    }
}
