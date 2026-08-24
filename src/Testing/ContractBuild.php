<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentEmitOptions;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\GitShow;
use Docuccino\Laravel\Support\Paths;
use JsonException;

/**
 * A freshly-generated document and the committed artifact beside it, for the two assertions that
 * compare the two.
 *
 * It goes through {@see DocumentBuilder} and {@see Formats}, which is the exact path `docuccino:export`
 * and `docuccino:diff` take, so an assertion can never disagree with the command about what the
 * document is or how it serialises.
 *
 * @internal
 */
final class ContractBuild
{
    private ?UirDocument $fresh = null;

    public function __construct(private readonly string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function config(): DocumentConfig
    {
        return $this->builder()->config($this->key);
    }

    public function exists(): bool
    {
        return $this->builder()->hasDocument($this->key);
    }

    /** Built once per instance: analysis is the expensive half and nothing changes under it mid-test. */
    public function fresh(): UirDocument
    {
        return $this->fresh ??= $this->builder()->build($this->key, app(TypeEngine::class))->document;
    }

    /**
     * Every byte string the exporter can legitimately write for this target.
     *
     * Provenance detail and whether ids are re-emitted are EMIT options, not facts about the contract —
     * `source.line` is explicitly not an identity input — so an artifact exported at a different
     * `--provenance` level is a differently-serialised copy of the same document, not a stale one.
     * Comparing against every form is what keeps `assertDocumentUpToDate()` about the contract.
     *
     * @return list<string>
     */
    public function emissions(ExportTarget $target): array
    {
        $document = $this->fresh();
        $yaml = $target->yaml() && Formats::serialisesYaml($target->format);
        $configured = DocumentEmitOptions::for($this->config());

        $variants = [];
        foreach (ProvenanceLevel::cases() as $provenance) {
            foreach ([true, false] as $keepIds) {
                $output = Formats::emit($target->format, $document, $configured
                    ->withKeepIds($keepIds)
                    ->withProvenance($provenance)
                    ->withYaml($yaml))->output;

                $variants[$output] = true;
            }
        }

        return array_keys($variants);
    }

    /** What `docuccino:export` writes with no flags — the form a failure message compares against. */
    public function canonicalEmission(ExportTarget $target): string
    {
        return Formats::emit($target->format, $this->fresh(), DocumentEmitOptions::for($this->config())
            ->withKeepIds()
            ->withProvenance(ProvenanceLevel::Winners)
            ->withYaml($target->yaml() && Formats::serialisesYaml($target->format)))->output;
    }

    public function absolute(string $path): string
    {
        return Paths::absolute($path, base_path());
    }

    /** The committed artifact's contents, or null when it is not there. */
    public function committed(string $path): ?string
    {
        $contents = @file_get_contents($this->absolute($path));

        return $contents === false ? null : $contents;
    }

    /**
     * The artifact as of a git ref, the way `docuccino:diff --against` reads it — the same reader, so
     * the assertion and the command can never disagree about what a ref resolves to.
     *
     * @return array{0: string|null, 1: string} contents (null on failure) and the reason
     */
    public function committedAtRef(string $ref, string $path): array
    {
        return GitShow::read($ref, $path);
    }

    /**
     * A committed artifact's JSON as a contract index, or the failure a suite author can act on. One
     * decoder for every path that reads one, so a torn file reports the same way whoever found it —
     * and one decode, because the index keeps the original text the JSON Schema half needs.
     */
    public static function indexOf(string $json, string $path): ContractIndex
    {
        try {
            return ContractIndex::fromJson($json);
        } catch (JsonException $exception) {
            throw UnreadableContract::notJson($path, $exception->getMessage());
        }
    }

    private function builder(): DocumentBuilder
    {
        return app(DocumentBuilder::class);
    }
}
