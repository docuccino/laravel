<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Workbench\App\Models\Form;

/**
 * Header declarations OpenAPI says every reader ignores, written the way an author writes them: three
 * ordinary `#[HeaderParameter]`s, two of them naming a header the spec reserves. Routed only ad-hoc
 * (never in the default route set), so no committed golden includes them.
 */
final class IgnoredHeaderController
{
    /** The media type the operation returns is already in the response's `content`, so only prose is lost. */
    #[Response(status: 200, type: Form::class)]
    #[HeaderParameter(name: 'Accept', description: 'Ask for JSON.')]
    public function negotiated(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** Nothing else on this operation says a credential is needed, so the document ends up saying nothing. */
    #[HeaderParameter(name: 'Authorization', description: 'Bearer your API token.')]
    public function credentialed(): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    /** The control: an ordinary header, published and unremarked. */
    #[HeaderParameter(name: 'X-Tenant', description: 'Which tenant the form belongs to.')]
    public function tenanted(Form $form): JsonResponse
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
