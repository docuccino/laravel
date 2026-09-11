<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * The emit options a DOCUMENT decides, as opposed to the ones a command line does.
 *
 * One owner, because three readers have to agree byte for byte: `docuccino:export` writes the
 * artifact, `docuccino:validate` re-emits it to hold it to its own published schema, and the contract
 * assertions re-emit it to check the committed copy is current. A key one of them read and the others
 * did not would make a fresh document look stale, or hold bytes nobody ships to a schema.
 *
 * @internal
 */
final class DocumentEmitOptions
{
    public static function for(DocumentConfig $config): EmitOptions
    {
        return (new EmitOptions)
            ->withMockFakerKey($config->mockFakerKey())
            ->withFormatSamples(RepresentationPolicy::fromConfig($config->representation)->formatSamples);
    }

    /**
     * The options a bare `docuccino:export` writes $target with — the document's own say plus the
     * defaults every flag on that command starts from.
     *
     * Whether the artifact is YAML is read off the target's path rather than passed in, because the
     * carrier is part of what a re-emission has to reproduce: checking the JSON serialisation of a
     * YAML target would hold the wrong bytes to the schema, and comparing against it would call a
     * current YAML artifact stale.
     */
    public static function canonical(DocumentConfig $config, ExportTarget $target): EmitOptions
    {
        return self::for($config)
            ->withKeepIds()
            ->withProvenance(ProvenanceLevel::Winners)
            ->withYaml($target->yaml() && Formats::serialisesYaml($target->format));
    }
}
