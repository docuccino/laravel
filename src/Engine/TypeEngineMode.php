<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

/**
 * How inference is composed (design §Inference). `null` skips analysis entirely (docblock/
 * attribute-only docs); `in-process` runs the analyser inside the build. An absent engine package
 * ({@see EnginePackage}), an unrecognised mode or any real-engine boot failure still leaves a
 * working build — see {@see TypeEngineFactory}.
 */
enum TypeEngineMode: string
{
    case Null = 'null';
    case InProcess = 'in-process';
}
