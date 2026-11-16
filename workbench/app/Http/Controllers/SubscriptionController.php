<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Workbench\App\Enums\ExportFault;
use Workbench\App\Http\Responses\AcceptedResponse;
use Workbench\App\Http\Responses\BranchingResponse;
use Workbench\App\Http\Responses\ConflictProblemResponse;
use Workbench\App\Http\Responses\ExpiredResponse;
use Workbench\App\Http\Responses\FaultStreamResponse;
use Workbench\App\Http\Responses\GoneJsonResponse;
use Workbench\App\Http\Responses\GoneResponse;
use Workbench\App\Http\Responses\MovedResponse;
use Workbench\App\Http\Responses\PartialFileResponse;
use Workbench\App\Http\Responses\QuotaResponse;
use Workbench\App\Http\Responses\RetryLaterResponse;

/**
 * Responses the application derives from the framework's so that the class names its status — a literal,
 * a constant, an enum case's read, its own parameter, a status set in its own constructor, one or two
 * classes below the framework — beside one whose status depends on a branch. Only ever analysed, never
 * dispatched.
 */
final class SubscriptionController
{
    /** Fetch a subscription that has been cancelled. */
    public function cancelled(): GoneResponse
    {
        return new GoneResponse;
    }

    /** Renew a subscription later. */
    public function renew(): RetryLaterResponse
    {
        return new RetryLaterResponse(['retry' => true]);
    }

    /** Renew a subscription too soon. */
    public function throttled(): RetryLaterResponse
    {
        return new RetryLaterResponse(['retry' => true], 429);
    }

    /** Follow a subscription to its new plan. */
    public function moved(): MovedResponse
    {
        return new MovedResponse('/plans/current');
    }

    /** Stream the invoices of a subscription that has lapsed. */
    public function invoices(): FaultStreamResponse
    {
        return new FaultStreamResponse(static function (): void {
            echo 'chunk';
        }, ExportFault::Gone);
    }

    /** Stream the invoices of a subscription, locked or lapsed. */
    public function ledger(bool $locked): FaultStreamResponse
    {
        return new FaultStreamResponse(static function (): void {
            echo 'chunk';
        }, $locked ? ExportFault::Locked : ExportFault::Gone);
    }

    /** Fetch part of a subscription's contract. */
    public function contract(): PartialFileResponse
    {
        return new PartialFileResponse('/tmp/contract.pdf');
    }

    /** Pause a subscription. */
    public function pause(): AcceptedResponse
    {
        return new AcceptedResponse;
    }

    /** Fetch a subscription whose trial has expired. */
    public function trial(): ExpiredResponse
    {
        return new ExpiredResponse;
    }

    /** Claim a subscription's handle. */
    public function handle(): ConflictProblemResponse
    {
        return new ConflictProblemResponse('The handle is taken.');
    }

    /** Fetch a subscription's archived plan. */
    public function archived(): GoneJsonResponse
    {
        return new GoneJsonResponse;
    }

    /** Upgrade a subscription past its quota. */
    public function upgrade(): QuotaResponse
    {
        return QuotaResponse::exceeded();
    }

    /** Fetch a subscription's notes. */
    public function notes(): BranchingResponse
    {
        return new BranchingResponse;
    }
}
