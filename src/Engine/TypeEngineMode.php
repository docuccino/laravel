<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

/**
 * How inference is composed (design §Inference). `null` skips analysis entirely (docblock/
 * attribute-only docs); the others select an engine composition. An absent engine package
 * ({@see EnginePackage}) or any real-engine boot failure still degrades to the null engine.
 */
enum TypeEngineMode: string
{
    case Null = 'null';
    case InProcess = 'in-process';
    case Orchestrated = 'orchestrated';
    case Caching = 'caching';
}
