<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Webhooks;

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Provenance\Source;

/**
 * One `#[Webhook]` class, resolved: the name and method it is published under, the type string its
 * body is read from, and the other attributes the class carries.
 *
 * @internal
 */
final readonly class WebhookDeclaration
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $files  the class's declaration files, for the fragment cache
     * @param  ?Source  $source  where the declaration was written, project-root-relative
     */
    public function __construct(
        public string $class,
        public string $name,
        public string $method,
        public string $payload,
        public string $mediaType,
        public AttributeSet $attributes,
        public ?string $summary = null,
        public ?string $description = null,
        public array $files = [],
        public ?Source $source = null,
    ) {}

    /** How a diagnostic and a diff name this webhook: the differ's own `webhooks.<name>` path. */
    public function signature(): string
    {
        return strtoupper($this->method).' webhooks.'.$this->name;
    }

    /**
     * Everything about this declaration that the fragment cache must key on and that is not answered
     * by re-hashing {@see $files}. The class's own file settles the rest.
     */
    public function cacheSignature(): string
    {
        return implode("\0", ['webhook', $this->class, $this->name, $this->method, $this->payload, $this->mediaType]);
    }
}
