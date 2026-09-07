<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ParameterBesideSchema;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * A parameter rename beside a schema rename on one change. The two name different kinds of node, so
 * neither can rot the other's target — which is the claim `VerbOrder` makes about the parameter verb,
 * and this is it executed rather than asserted.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`, and publishes `title` where it published `name`.')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class FormsSearchTookQAndPublishedName {}
