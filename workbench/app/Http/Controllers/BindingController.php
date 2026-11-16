<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\Season;
use Workbench\App\Models\Almanac;
use Workbench\App\Models\Article;
use Workbench\App\Models\Beacon;
use Workbench\App\Models\Coupon;
use Workbench\App\Models\Dossier;
use Workbench\App\Models\Form;
use Workbench\App\Models\Journal;
use Workbench\App\Models\Ledger;
use Workbench\App\Models\Voucher;

/**
 * Every kind of route binding Laravel resolves, one action each: the plain implicit one, a route that
 * names its own column, a nested child the router scopes to its parent, a model that decides its key
 * in a method body, a string-backed enum, a segment the application resolves with a binder of its
 * own, one it binds to a model with `Route::model()`, and models that resolve their bindings themselves. What each one lets the document say about the path parameter is different, and several of them
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

    /** Show one journal, found by the day it was filed. */
    #[Group('Bindings')]
    public function showJournal(Journal $journal): JsonResponse
    {
        return response()->json($journal);
    }

    /** Show one almanac, found by the day it was recorded. */
    #[Group('Bindings')]
    public function showAlmanac(Almanac $almanac): JsonResponse
    {
        return response()->json($almanac);
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

    /** Show a dossier. */
    #[Group('Bindings')]
    public function showDossier(Dossier $dossier): JsonResponse
    {
        return response()->json($dossier);
    }

    /** Show a ledger the application binds with `Route::model()`, so the signature names no type. */
    #[Group('Bindings')]
    public function showExplicit(mixed $explicit): JsonResponse
    {
        return response()->json($explicit);
    }

    /** Show a ledger bound with `Route::model()` and a fallback for values the key does not find. */
    #[Group('Bindings')]
    public function showFallback(mixed $fallback): JsonResponse
    {
        return response()->json($fallback);
    }

    /** Show a voucher by the code it hands out. */
    #[Group('Bindings')]
    public function showVoucher(Voucher $voucher): JsonResponse
    {
        return response()->json($voucher);
    }

    /** Show one form redeemed against a voucher. */
    #[Group('Bindings')]
    public function showVoucherEntry(Voucher $voucher, Form $entry): JsonResponse
    {
        return response()->json($entry);
    }

    /** Show a coupon by its public id. */
    #[Group('Bindings')]
    public function showCoupon(Coupon $coupon): JsonResponse
    {
        return response()->json($coupon);
    }

    /** Show one form filed under a coupon. */
    #[Group('Bindings')]
    public function showCouponEntry(Coupon $coupon, Form $entry): JsonResponse
    {
        return response()->json($entry);
    }

    /** Show a beacon by its uuid. */
    #[Group('Bindings')]
    public function showBeacon(Beacon $beacon): JsonResponse
    {
        return response()->json($beacon);
    }
}
