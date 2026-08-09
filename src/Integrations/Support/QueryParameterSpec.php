<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Patch\Contribution;

/**
 * One query parameter an integration contributes, already resolved through the representation policy. A
 * plain assertable value the builder returns and the extension writes onto the draft — always
 * `in: query`, always optional.
 */
final readonly class QueryParameterSpec
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
        public ?string $description = null,
        public ?string $style = null,
        public ?bool $explode = null,
        public mixed $example = null,
    ) {}

    /**
     * Writes the whole spec onto a {@see ParameterDraft}. One applier, so no consumer re-implements this
     * and quietly drops style/explode. A deepObject's `properties` map goes in as nested property drafts
     * rather than one opaque value, so a later `#[QueryParameter('filter[child]')]` can patch a single
     * property with its own provenance.
     */
    public function applyTo(ParameterDraft $parameter, Contribution $contribution): void
    {
        $parameter->setRequired(false, $contribution);

        if ($this->description !== null) {
            $parameter->setDescription($this->description, $contribution);
        }
        if ($this->style !== null) {
            $parameter->set('style', $this->style, $contribution);
        }
        if ($this->explode !== null) {
            $parameter->set('explode', $this->explode, $contribution);
        }
        if ($this->example !== null) {
            $parameter->set('example', $this->example, $contribution);
        }

        foreach ($this->schema as $keyword => $value) {
            if ($keyword === 'properties' && is_array($value)) {
                $this->applyProperties($parameter, $value, $contribution);

                continue;
            }

            $parameter->schema()->set((string) $keyword, $value, $contribution);
        }
    }

    /**
     * Each property keyword-by-keyword through its own guard, so a later attribute patch merges onto one
     * property instead of replacing the whole map.
     *
     * @param  array<array-key, mixed>  $properties
     */
    private function applyProperties(ParameterDraft $parameter, array $properties, Contribution $contribution): void
    {
        foreach ($properties as $name => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $property = $parameter->schema()->property((string) $name);
            foreach ($schema as $keyword => $value) {
                $property->set((string) $keyword, $value, $contribution);
            }
        }
    }
}
