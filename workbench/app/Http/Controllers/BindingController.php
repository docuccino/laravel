<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\Season;
use Workbench\App\Models\Article;
use Workbench\App\Models\Form;
use Workbench\App\Models\Ledger;

/**
 * Every kind of route binding Laravel resolves, one action each: the plain implicit one, a route that
 * names its own column, a nested child the router scopes to its parent, a model that decides its key
 * in a method body, a string-backed enum, and a segment the application resolves with a binder of its
 * own. What each one lets the document say about the path parameter is different, and several of them
 * are what it must NOT say.
 */
final class BindingController
{
    /** Show a ledger by its human reference. */
    #[Group('Bindings')]
    public function showByReference(Ledger $ledger): JsonResponse
    {
        return response()->json($ledger);
    }

    /** Show one entry filed against a ledger. */
    #[Group('Bindings')]
    public function showEntry(Ledger $ledger, Form $entry): JsonResponse
    {
        return response()->json($entry);
    }

    /** Show one entry of a ledger, found by its title. */
    #[Group('Bindings')]
    public function showEntryByTitle(Ledger $ledger, Form $entry): JsonResponse
    {
        return response()->json($entry);
    }

    /** Show an article. */
    #[Group('Bindings')]
    public function showArticle(Article $article): JsonResponse
    {
        return response()->json($article);
    }

    /** Show the forms filed in one season. */
    #[Group('Bindings')]
    public function showSeason(Season $season): JsonResponse
    {
        return response()->json(['season' => $season->value]);
    }

    /** Show a form the application resolves with a binder of its own. */
    #[Group('Bindings')]
    public function showBound(Form $custom): JsonResponse
    {
        return response()->json($custom);
    }
}
