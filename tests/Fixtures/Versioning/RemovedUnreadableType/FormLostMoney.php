<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedUnreadableType;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/**
 * A type that is neither a class this document publishes nor an OpenAPI type name. The field goes back
 * with no shape — valid and vague beats precise and false — and the build says the declaration asked
 * for something it did not get.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `price`.')]
#[RemovedResponseField(schema: FormData::class, field: 'price', type: 'App\\Support\\Money')]
final class FormLostMoney {}
