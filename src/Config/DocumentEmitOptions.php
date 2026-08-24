<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;

/**
 * The emit options a DOCUMENT decides, as opposed to the ones a command line does.
 *
 * One owner, because two readers have to agree byte for byte: `docuccino:export` writes the artifact
 * and the contract assertions re-emit it to check the committed copy is current. A key one of them
 * read and the other did not would make a fresh document look stale.
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
}
