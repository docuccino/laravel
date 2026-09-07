<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\Example;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\OperationId;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\FormData;
use Workbench\App\Http\Requests\SearchFormsRequest;
use Workbench\App\Http\Requests\StoreVersionedFormRequest;

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
     * Create a form.
     *
     * Records a form under the title given and returns it, unpublished.
     */
    #[Group('Forms')]
    #[OperationId('createVersionedForm')]
    #[Response(status: 201, type: FormData::class, description: 'The form that was created.')]
    #[Example(request: true, value: ['title' => 'Onboarding'])]
    public function store(StoreVersionedFormRequest $request): JsonResponse
    {
        /** @var string $title */
        $title = $request->validated('title');

        return response()->json(new FormData(id: 3, title: $title, publishedAt: null), 201);
    }

    /**
     * Search published forms.
     *
     * The read verb of the same resource, so its validation rules land as QUERY parameters rather than
     * as a body — which is the only shape a parameter rename has anything to say about.
     */
    #[Group('Forms')]
    #[OperationId('searchVersionedForms')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The matching forms.')]
    public function search(SearchFormsRequest $request): JsonResponse
    {
        return $this->index();
    }

    /**
     * Fetch one form, addressed in every place a parameter can travel.
     *
     * One parameter per OpenAPI location, which is what a verb that can only address some of them needs
     * a real document to be right about: three of these move, and the path one is named by the URL
     * template as well as by the parameter, so nothing can move it on its own.
     */
    #[Group('Forms')]
    #[OperationId('locateVersionedForm')]
    #[PathParameter('formId', description: 'The form to fetch.')]
    #[QueryParameter('fields', description: 'The members to return.')]
    #[HeaderParameter('X-Trace', description: 'Correlate this call with your own logs.')]
    #[CookieParameter('session', description: 'The session the caller is signed in with.')]
    #[Response(status: 200, type: FormData::class, description: 'The form.')]
    public function locate(string $formId): JsonResponse
    {
        return response()->json(new FormData(id: (int) $formId, title: 'Onboarding', publishedAt: null));
    }

    /**
     * Search published forms, still accepting the name the search took before.
     *
     * A route mid-migration: `search` is what the code documents and `q` is what it has not stopped
     * taking yet. An older version cannot be told it took `q` — this operation takes both — and that is
     * a refusal about THIS operation rather than about the change.
     */
    #[Group('Forms')]
    #[OperationId('searchVersionedFormsEitherWay')]
    #[QueryParameter('search', description: 'The text to match.')]
    #[QueryParameter('q', description: 'The text to match, as it was spelled before.')]
    #[Response(status: 200, type: 'list<FormData>', description: 'The matching forms.')]
    public function searchEitherWay(): JsonResponse
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
