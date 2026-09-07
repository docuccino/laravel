<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequestUnresolved;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Workbench\App\Data\FormData;

/** A request rename over a class the document publishes a RESPONSE shape for and no request body. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form is submitted with `title` where it was submitted with `name`.')]
#[RenamedRequestField(schema: FormData::class, from: 'name', to: 'title')]
final class FormsWereSubmittedWithAName {}
