<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

/** Why an export was refused — a closed set the API really answers with, so the document publishes it. */
enum ExportFailure: string
{
    case QuotaExceeded = 'quota-exceeded';
    case SourceUnavailable = 'source-unavailable';
}
