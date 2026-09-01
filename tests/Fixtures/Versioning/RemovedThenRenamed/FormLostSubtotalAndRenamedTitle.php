<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedThenRenamed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * One change carrying a removal and a rename, which is the pair `VerbOrder` has to decide between.
 *
 * The removal counts where `subtotal` lands against the names already standing, and the rename changes
 * one of those names — `title` becomes `name`. Removal first, the count is taken against the schema the
 * CODE publishes and `subtotal` lands after `title`; rename first, it is counted against a spelling this
 * change itself invented and lands before `name` instead.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms publish `title` and no longer publish `subtotal`.')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class FormLostSubtotalAndRenamedTitle {}
