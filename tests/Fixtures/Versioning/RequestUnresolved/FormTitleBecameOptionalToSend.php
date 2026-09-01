<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RequestUnresolved;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Workbench\App\Data\FormData;

/**
 * A request verb over a class the document publishes only as a RESPONSE. The response node is right
 * there and must not be touched, so this reports that there is no request body schema to edit — which
 * is the sentence that tells a reader why, in a document that plainly publishes a `FormData`.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Posting a form no longer requires `title`.')]
#[MadeRequestFieldOptional(schema: FormData::class, field: 'title')]
final class FormTitleBecameOptionalToSend {}
