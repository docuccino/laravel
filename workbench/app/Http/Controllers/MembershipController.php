<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\ArchiveFileResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\ChunkedResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\EnvelopeResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\FeedResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\LegacyGateway;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\NoticeResponse;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package\RelayRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use Workbench\App\Http\Responses\PendingJsonResponse;

/**
 * Responses that come back as the class they are rather than the status and body they send: a package's
 * subclass of each framework response, whose constructor is not the application's to read, Symfony's own
 * JSON response as a package's collaborator hands it back, and the application's JSON response setting its
 * status on one branch. Only ever analysed, never dispatched.
 */
final class MembershipController
{
    /** Fetch a membership. */
    public function show(): EnvelopeResponse
    {
        return new EnvelopeResponse(['id' => 1]);
    }

    /** Fetch a membership from the legacy store. */
    public function legacy(LegacyGateway $gateway): SymfonyJsonResponse
    {
        return $gateway->fetch(1);
    }

    /** Queue a membership for review. */
    public function queue(): PendingJsonResponse
    {
        return new PendingJsonResponse(['queued' => true]);
    }

    /** Follow a membership to its portal. */
    public function portal(): RelayRedirectResponse
    {
        return new RelayRedirectResponse('/portal');
    }

    /** Download a membership's archive. */
    public function archive(): ArchiveFileResponse
    {
        return new ArchiveFileResponse('/tmp/membership.zip');
    }

    /** Stream a membership's activity. */
    public function activity(): ChunkedResponse
    {
        return new ChunkedResponse(static function (): void {
            echo 'chunk';
        });
    }

    /** Stream a membership's renewals. */
    public function renewals(): FeedResponse
    {
        return new FeedResponse([]);
    }

    /** Fetch a membership's welcome notice. */
    public function notice(): NoticeResponse
    {
        return new NoticeResponse('Welcome aboard.');
    }
}
