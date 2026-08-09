<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;

/**
 * How inference is composed (design §Inference). `null` skips PHPStan entirely (docblock/
 * attribute-only docs); the others select a {@see PhpStanEngineFactory}
 * composition. Any real-engine boot failure still degrades to the null engine.
 */
enum TypeEngineMode: string
{
    case Null = 'null';
    case InProcess = 'in-process';
    case Orchestrated = 'orchestrated';
    case Caching = 'caching';
}
