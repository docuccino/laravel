<?php

declare(strict_types=1);

namespace Workbench\App\Webhooks;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Webhook;

/**
 * A form was submitted.
 *
 * Delivered once the submission has been stored, and retried with an exponential backoff until the
 * receiving endpoint answers with a 2xx.
 */
#[Webhook('form.submitted')]
#[Group('Forms')]
final readonly class FormSubmitted
{
    public function __construct(
        public int $formId,
        public string $submittedAt,
    ) {}
}
