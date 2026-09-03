<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\ArtifactLocator;
use Illuminate\Testing\TestResponse;
use JsonException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract assertions for a PHPUnit test case or a Pest `uses()`.
 *
 * Your suite already exercises real endpoints with real data. These hold every one of those exchanges
 * to the document Docuccino generates, and — because the document carries `x-docuccino.provenance` —
 * every failure names the producer and the `file:line` the shape came from, which is where the fix
 * usually is.
 *
 * `ApiContract::registerMacros()` puts the three exchange assertions on `TestResponse` too, if you
 * prefer `$this->getJson(…)->assertValidResponse()` to wrapping the call. {@see assertValidWebhook()}
 * has no response to hang off, so it stays a method.
 */
trait AssertsApiContract
{
    /**
     * Record what each request arrives carrying, before the application's own middleware can rewrite
     * it — `TrimStrings` and `ConvertEmptyStringsToNull` are in Laravel's default global stack, and any
     * `$request->merge()` adds fields no client sent.
     *
     * Laravel calls `setUp<Trait>()` for every trait a test case takes, so this runs once per
     * application, which is what the capture needs: a bootstrap called once cannot reach an application
     * that is built again for every test. {@see ApiContract::captureRequestBodies()} is the same call for
     * a suite that reaches the assertions without the trait.
     */
    protected function setUpAssertsApiContract(): void
    {
        ApiContract::captureRequestBodies();
    }

    /**
     * The request that produced this response matches what the contract documents for the operation.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidRequest(TestResponse $response): TestResponse
    {
        ApiContract::assertExchange($response, true, false);

        return $response;
    }

    /**
     * The response matches the schema the contract documents for its status and media type.
     *
     * `recordAs:` names the scenario this test set up — `recordAs: 'empty-cart'` — and naming it is
     * what asks for it to be PUBLISHED, as that entry of the operation's `examples` map. Checking is
     * every exchange's business; publishing is a choice, so an assertion that names nothing checks the
     * response and records none of it. Ignored when nothing is recording.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidResponse(TestResponse $response, ?string $recordAs = null): TestResponse
    {
        ApiContract::assertExchange($response, false, true, $recordAs);

        return $response;
    }

    /**
     * Both halves at once. `recordAs:` names the scenario to publish, as on {@see assertValidResponse()},
     * and nothing is recorded without it.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidExchange(TestResponse $response, ?string $recordAs = null): TestResponse
    {
        ApiContract::assertExchange($response, true, true, $recordAs);

        return $response;
    }

    /**
     * The payload your application dispatches for a documented webhook satisfies the schema the
     * document publishes for it.
     *
     * The outbound half of the contract, which no HTTP test can reach: `$payload` is whatever the code
     * holds at the moment it delivers — the event object, a Data object, an array, JSON text — and it is
     * held to the webhook's documented body exactly as a request body is.
     *
     * `method:` names one of the methods a webhook is published under, and is only needed for a name
     * published under more than one.
     */
    public function assertValidWebhook(string $name, mixed $payload, ?string $method = null): void
    {
        ApiContract::assertWebhook($name, $payload, $method);
    }

    /**
     * Every example the document publishes satisfies the schema beside it.
     *
     * Inference cannot be wrong about a shape it derived; a hand-written `#[Example]` can say anything
     * at all, and it is the part of the document a reader copies. This is the one check that holds it
     * to the same contract a client is held to.
     */
    public function assertValidExamples(): void
    {
        $report = (new ExampleAudit(ApiContract::index()))->run();

        Assert::assertTrue($report->ok(), ContractMessages::examples($report));
    }

    /**
     * The document this code generates makes no breaking change to the committed artifact.
     *
     * It runs `DocumentDiffer` — the same semantic, id-based diff `docuccino:diff` runs — so the
     * assertion and the command can never disagree about what breaking means. Pass a git ref to compare
     * against a branch or tag rather than the working tree, exactly as `--against` does.
     */
    public function assertNoBreakingChanges(?string $against = null): void
    {
        $build = ApiContract::build();
        $target = ArtifactLocator::preferred($build->config());

        if ($against === null) {
            $json = $build->committed($target->path);
            $problem = sprintf('%s is not there', $build->absolute($target->path));
        } else {
            [$json, $stderr] = $build->committedAtRef($against, $target->path);
            $problem = sprintf('git show %s:%s failed — %s', $against, $target->path, $stderr);
        }

        if ($json === null) {
            Assert::fail(sprintf(
                "There is no committed contract to compare against: %s.\n".
                'Export one and commit it: php artisan docuccino:export',
                PlainText::of($problem),
            ));
        }

        $old = ContractBuild::indexOf($json, $build->absolute($target->path));
        $new = $build->fresh();

        try {
            $changeset = (new DocumentDiffer)->diff($old->comparable(), $new);
        } catch (IncomparableDocumentsException $exception) {
            Assert::fail($exception->getMessage());
        }

        Assert::assertFalse($changeset->isBreaking(), ContractMessages::breaking(
            $changeset,
            ContractIndex::fromArray($new->toArray()),
            $old,
            'If the change is deliberate, re-export the artifact and raise the document version: php artisan docuccino:export',
        ));
    }

    /**
     * The committed artifacts are what this code produces right now.
     *
     * A BYTE comparison, which is only a fair test because Docuccino's output is deterministic:
     * identical code produces identical bytes, so a difference always has a cause. Emit options that do
     * not change the contract — how much provenance detail is kept, whether ids are re-emitted — are
     * compared across every form the exporter can write, so a document exported at a different
     * `--provenance` level does not read as stale.
     */
    public function assertDocumentUpToDate(): void
    {
        $build = ApiContract::build();
        $targets = $build->config()->exportTargets();

        foreach ($targets as $target) {
            $committed = $build->committed($target->path);

            if ($committed === null) {
                Assert::fail(sprintf(
                    "%s has never been written.\nExport it and commit it: php artisan docuccino:export",
                    PlainText::of($build->absolute($target->path)),
                ));
            }

            if (in_array($committed, $build->emissions($target), true)) {
                continue;
            }

            Assert::fail(ContractMessages::stale(
                $target->path,
                ...$this->staleDetail($build, $target, $committed),
                hint: 'Regenerate it: php artisan docuccino:export',
            ));
        }

        // A document with no target would otherwise pass having compared nothing.
        Assert::assertThat($targets, Assert::logicalNot(Assert::isEmpty()));
    }

    /**
     * The changeset and both indexes behind a stale artifact, or nulls when the committed file is not a
     * document that can be compared semantically (a Postman collection, say).
     *
     * @return array{0: Changeset|null, 1: ContractIndex|null, 2: ContractIndex|null}
     */
    private function staleDetail(ContractBuild $build, ExportTarget $target, string $committed): array
    {
        if (! in_array($target->format, Formats::contractPreference(), true) || $target->yaml()) {
            return [null, null, null];
        }

        try {
            $old = ContractIndex::fromJson($committed);
        } catch (JsonException) {
            return [null, null, null];
        }

        $new = $build->fresh();

        try {
            $changeset = (new DocumentDiffer)->diff($old->comparable(), $new);
        } catch (IncomparableDocumentsException) {
            return [null, null, null];
        }

        return [$changeset, ContractIndex::fromArray($new->toArray()), $old];
    }
}
