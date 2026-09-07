<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamInPath;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/**
 * A rename over the one location nothing can move: a path parameter's name is stated on the parameter
 * and again as the `{expression}` of the path it stands under, and only the first is something a change
 * can address.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form is fetched by `formId` where it was fetched by `identifier`.')]
#[RenamedParameter(in: 'path', from: 'identifier', to: 'formId')]
final class TookTheFormIdAsAnIdentifier {}
