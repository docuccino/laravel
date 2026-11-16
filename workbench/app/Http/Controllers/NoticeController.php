<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workbench\App\Http\Responses\ExpiredResponse;
use Workbench\App\Http\Responses\LockedNoticeResponse;

/**
 * Plain responses handed a string, nothing, or an array, in every spelling that makes one: constructed,
 * through the `response()` helper, the factory and the facade, Symfony's own, and a subclass handing its
 * parent the content; and content of no one kind, with and without a `Content-Type` header.
 */
final class NoticeController
{
    /** Read the expiry notice. */
    public function constructed(): Response
    {
        return new Response('The export has expired.', 410);
    }

    /** Clear the expiry notice. */
    public function constructedEmpty(): Response
    {
        return new Response('', 410);
    }

    /** Read the lock notice. */
    public function constructedArray(): Response
    {
        return new Response(['error' => 'locked'], 422);
    }

    /** Export the notices. */
    public function constructedCsv(): Response
    {
        return new Response('id,name', 200, ['Content-Type' => 'text/csv']);
    }

    /** Read the lock notice as text. */
    public function constructedArrayLabelled(): Response
    {
        return new Response(['error' => 'locked'], 422, ['Content-Type' => 'text/plain']);
    }

    /** Read the archived notice. */
    public function helper(): Response
    {
        return response('The export has expired.', 410);
    }

    /** Acknowledge a notice. */
    public function helperEmpty(): Response
    {
        return response('');
    }

    /** Read the notice summary. */
    public function helperArray(): Response
    {
        return response(['error' => 'locked']);
    }

    /** Read the archived notice as text. */
    public function helperPlain(): Response
    {
        return response('The export has expired.', 410, ['Content-Type' => 'text/plain']);
    }

    /** Read the maintenance notice. */
    public function helperMessage(string $message): Response
    {
        return response($message, 503);
    }

    /** Show the archived notice page. */
    public function helperView(): Response
    {
        return response(view('archived'), 410);
    }

    /** Read the facade's notice. */
    public function facade(): Response
    {
        return ResponseFactory::make('The export has expired.', 410);
    }

    /** Read the factory's notice. */
    public function factory(): Response
    {
        return response()->make('The export has expired.', 410);
    }

    /** Read the transport notice. */
    public function symfony(): SymfonyResponse
    {
        return new SymfonyResponse('The export has expired.', 410);
    }

    /** Read the expired notice. */
    public function subclass(): ExpiredResponse
    {
        return new ExpiredResponse('The export has expired.');
    }

    /** Read the locked notice. */
    public function subclassArray(): LockedNoticeResponse
    {
        return new LockedNoticeResponse;
    }

    /** Read the cached notice. */
    public function helperCached(): Response
    {
        return response(cache()->get('notice'), 410);
    }

    /** Read the cached notice, whatever it holds. */
    public function helperCachedOk(): Response
    {
        return response(cache()->get('notice'));
    }

    /** Read the notice as text or fields. */
    public function constructedTextOrArray(): Response
    {
        return new Response($this->notice(), 410);
    }

    /** Export the cached notices. */
    public function helperCachedCsv(): Response
    {
        return response(cache()->get('export'), 200, ['Content-Type' => 'text/csv']);
    }

    /** Read the notice image. */
    public function helperFile(): Response
    {
        return response(file_get_contents(storage_path('notice.png')), 200, ['Content-Type' => 'image/png']);
    }

    /** Read the transport's cached notice. */
    public function symfonyCached(): SymfonyResponse
    {
        return new SymfonyResponse(cache()->get('notice'), 410);
    }

    /** @return string|array<string, string> */
    private function notice(): string|array
    {
        return cache()->has('notice') ? ['error' => 'expired'] : 'The export has expired.';
    }
}
