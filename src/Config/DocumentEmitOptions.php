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
            ->withYaml($target->yaml() && Formats::serialisesYaml($target->format))
            ->withSourceUrl(self::openApiBeside($config, $target));
    }

    /**
     * What an artifact that POINTS AT the OpenAPI document should call it — today the Arazzo workflow
     * description, whose `sourceDescriptions` names the description its steps' operations live in.
     *
     * **Relative to the pointing artifact's own directory, and never absolute.** The two files are
     * exported side by side and the pointer travels with them, so a relative reference is the one that
     * keeps working wherever they are served. An absolute path would also put the machine that built
     * the document into a file that gets PUBLISHED, which is the leak the whole build guards against
     * everywhere else.
     *
     * Falls back to the OpenAPI default name where the document configures no OpenAPI target: a
     * pointer at the conventional name beats no source description, which Arazzo does not allow.
     */
    private static function openApiBeside(DocumentConfig $config, ExportTarget $target): string
    {
        foreach ($config->exportTargets() as $candidate) {
            if (Formats::publishesPlainOpenApi($candidate->format)) {
                return self::relative($candidate->path, $target->path);
            }
        }

        return 'openapi.json';
    }

    /**
     * `$path` as seen from the directory `$from` sits in. Both are project-relative already, so this is
     * a walk over their segments rather than anything that touches the filesystem — nothing here may
     * resolve against the build machine.
     */
    private static function relative(string $path, string $from): string
    {
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $part): bool => $part !== ''));
        $base = array_values(array_filter(explode('/', str_replace('\\', '/', $from)), static fn (string $part): bool => $part !== ''));

        // The file's own name is not part of the directory it sits in.
        array_pop($base);

        while ($to !== [] && $base !== [] && $to[0] === $base[0]) {
            array_shift($to);
            array_shift($base);
        }

        return implode('/', [...array_fill(0, count($base), '..'), ...$to]);
    }
}
