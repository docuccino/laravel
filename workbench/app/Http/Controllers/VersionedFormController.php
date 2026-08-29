<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Example;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\OperationId;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\FormData;

/**
 * A forms list that really returns what it documents — `FormData` as the code publishes it today,
 * `title` and all. {@see FormController::index()} deliberately disagrees with its own document (a test
 * pins the disagreement), so it cannot stand in for a body anyone asserts against.
 */
final class VersionedFormController
{
    /**
     * List published forms.
     *
     * Returns the published forms with their identifiers, titles and publication timestamps.
     */
    #[Group('Forms')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The published forms.')]
    #[Example(value: [['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z']])]
    public function index(): JsonResponse
    {
        return response()->json([
            new FormData(id: 1, title: 'Onboarding', publishedAt: '2026-08-01T09:00:00Z'),
            new FormData(id: 2, title: 'Offboarding', publishedAt: null),
        ]);
    }

    /**
     * List archived forms.
     *
     * Returns the forms that are no longer published, in the same shape as the published ones — a
     * second operation over one shared component, which is what a scoped version change has to fork.
     */
    #[Group('Forms')]
    #[OperationId('listArchivedForms')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The archived forms.')]
    public function archived(): JsonResponse
    {
        return response()->json([
            new FormData(id: 3, title: 'Contractor onboarding', publishedAt: null),
        ]);
    }

    /**
     * List published forms, with the version header documented by hand.
     *
     * Returns the published forms exactly as {@see index()} does; the difference is the declaration
     * above it, which an application that wants its own wording for the version header would write.
     */
    #[Group('Forms')]
    #[HeaderParameter('X-Api-Version', description: 'Pin the API version, or take the current one.')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The published forms.')]
    public function documented(): JsonResponse
    {
        return $this->index();
    }

    /**
     * List published forms, with two named examples.
     *
     * The same body as {@see index()}; the difference is that the examples are named, which is the map
     * form of the member rather than the single value one.
     */
    #[Group('Forms')]
    #[OperationId('listNamedExampleForms')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The published forms.')]
    #[Example(value: [['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z']], name: 'published', summary: 'A form with a publication date')]
    #[Example(value: [['id' => 2, 'title' => 'Offboarding', 'publishedAt' => null]], name: 'unpublished')]
    public function named(): JsonResponse
    {
        return $this->index();
    }

    /**
     * List published forms, with an example of ONE form where the response is a list of them.
     *
     * The mistake an author makes when the example is written from the shape of a row rather than from
     * the shape of the body. The document publishes it as written; what a version change can do with it
     * is what this is here to pin.
     */
    #[Group('Forms')]
    #[OperationId('listSingleExampleForms')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The published forms.')]
    #[Example(value: ['id' => 1, 'title' => 'Onboarding', 'publishedAt' => null])]
    public function single(): JsonResponse
    {
        return $this->index();
    }
}
