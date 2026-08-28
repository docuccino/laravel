<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\MediaType;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\ProcessRecordingLedger;
use Docuccino\Core\Examples\RecordedBody;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingLedger;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Examples\SharedRecordingLedger;
use Docuccino\Core\Examples\UnlockableRecording;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;
use JsonException;

/**
 * Turns the responses your suite already produces into the examples the document publishes.
 *
 * It is a {@see ContractObserver}, which is the whole point: the assertion path has already matched the
 * exchange to its operation and already checked the body against the documented schema, so a recorder
 * hung off that seam needs no matching logic of its own and can only ever record a response that
 * agreed with the contract at the moment it was recorded.
 *
 * What it writes is a committed file per operation, named after the operation's stable id. The
 * document build reads those files and nothing else, so "Docuccino never executes your application
 * code" stays exactly as true as it was: the execution is your test suite's, which is where it already
 * lived.
 *
 * Recording is opt-in at each assertion, and `recordAs:` is how it is asked for. Checking an exchange
 * and publishing it as documentation are two decisions with opposite ideal coverage: a suite should
 * check as many exchanges as it can, because that is how a contract defect is found, and publish ONE
 * deliberately chosen response per operation, because that is documentation. Tied together, the second
 * one is made by whichever test happened to answer with the best-ranking body — which is how a
 * generated fixture becomes the illustration of an endpoint. So an assertion that names no scenario
 * checks and records nothing.
 *
 * Four rules do the curating, and each of them is about the recording being a function of the
 * responses rather than of the run:
 *
 * - only an exchange the caller NAMED is recorded, so what a document shows is chosen rather than won;
 * - only an exchange whose RESPONSE half was checked and passed is recorded, so a body that
 *   contradicts its own schema can never become the illustration of it;
 * - among the responses a suite records under one name, the published one is the best
 *   ({@see RecordedExample::outranks()}) and never the first, so reordering tests moves nothing;
 * - a committed body is left alone while its SHAPE is unchanged, so a timestamp or an autoincrement
 *   key in a payload cannot make the artifact churn on every re-record.
 *
 * The name is always the caller's: deriving one from the test's own name would make renaming a test
 * rename a published example, which is a contract change nobody asked for.
 *
 * Credentials are replaced on the way out ({@see ExampleRedaction}), before anything reaches disk.
 */
final class ExampleRecorder implements ContractObserver
{
    private ?RecordingLedger $ledger = null;

    private ?RecordingStore $store = null;

    public function __construct(
        private readonly ?string $directory = null,
        private ?ExampleRedaction $redaction = null,
    ) {
        // Coverage refuses under a parallel runner because it is an aggregate nobody inside the run can
        // take; a recording is per-operation, so workers merge theirs under a lock instead. That lock is
        // keyed by the RUN, and a platform that cannot name the run is one where refusing is still the
        // only honest answer.
        if (ParallelRun::active() && ParallelRun::runKey() === null) {
            throw UnrecordableRun::indeterminate(ParallelRun::worker());
        }
    }

    public function observed(ObservedExchange $exchange): void
    {
        $operationId = $exchange->operationId();
        $name = $exchange->recordAs;

        // A name is a call-site literal, so it is answered where it was written — before anything else
        // here, so a typo is reported by the exchange that wrote it rather than by whichever build
        // eventually reads the file.
        if ($name !== null && ! RecordedExample::isLegalName($name)) {
            throw UnrecordableRun::badName($name);
        }

        if ($operationId === null) {
            return;
        }

        // Where the recordings go is wiring, and wiring is wrong for a whole suite or for none of it.
        // Resolved for every exchange this recorder could have published rather than only for the named
        // ones, so a recorder pointed at nowhere still says so in the suite that has not named a
        // scenario yet — which is every suite, the first time.
        $this->store();

        if ($name === null) {
            return;
        }

        $example = $this->exampleFor($exchange, $name);

        if ($example === null) {
            return;
        }

        try {
            $this->ledger()->record($operationId, $exchange->method().' '.$exchange->pathTemplate(), $example);
        } catch (UnlockableRecording $failure) {
            throw UnrecordableRun::unlockable($failure);
        }
    }

    /**
     * The redaction rules, resolved on first use from the container so they carry the application's own
     * `lint.leakage` heuristics — a test bootstrap constructs the recorder before there is a container
     * to ask.
     */
    private function redaction(): ExampleRedaction
    {
        return $this->redaction ??= app(ExampleRedaction::class);
    }

    /** Where recordings are being written, resolved on first use — a test bootstrap runs early. */
    public function store(): RecordingStore
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $document = ApiContract::documentKey();
        $store = $this->directory !== null
            ? new RecordingStore(Paths::absolute($this->directory, base_path()))
            : RecordingStore::for(ApiContract::build()->config(), base_path());

        if ($store === null) {
            throw UnrecordableRun::unconfigured($document);
        }

        return $this->store = $store;
    }

    /**
     * Where this process puts what it records: its own memory when it is the only one recording, a
     * locked file when it is one worker of a parallel run.
     */
    private function ledger(): RecordingLedger
    {
        if ($this->ledger !== null) {
            return $this->ledger;
        }

        $run = ParallelRun::active() ? ParallelRun::runKey() : null;

        return $this->ledger = $run === null
            ? new ProcessRecordingLedger($this->store())
            : new SharedRecordingLedger($this->store(), $run);
    }

    /**
     * The example this exchange offers, or null when it offers none.
     *
     * A response that was not checked is not evidence of anything — the suite asserted the request half
     * and said nothing about the body — and neither is one that failed. A body JSON Schema could not
     * check (a CSV download, an image) is not an example a document can publish either.
     */
    private function exampleFor(ObservedExchange $exchange, string $name): ?RecordedExample
    {
        $outcome = $exchange->result->response;

        if ($outcome === null || ! $outcome->ok()) {
            return null;
        }

        $mediaType = MediaType::base($exchange->exchange->responseContentType);

        if ($mediaType === null || ! MediaType::isJson($mediaType)) {
            return null;
        }

        $body = $exchange->body();

        if ($body === '') {
            return null;
        }

        try {
            $decoded = RecordedBody::decode($body);
        } catch (JsonException) {
            return null;
        }

        [$redacted] = $this->redaction()->apply($decoded);

        return RecordedExample::of((string) $exchange->status(), $mediaType, $redacted, $name);
    }
}
