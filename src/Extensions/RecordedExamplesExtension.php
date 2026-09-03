<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;

/**
 * Publishes the response bodies a test suite recorded as the examples for their operation: a committed
 * file is read, nothing is dispatched and no database is opened.
 *
 * Which of a recording and an `#[Example]` publishes is the draft's answer rather than this class's — a
 * recording fills an ILLUSTRATION bag and an attribute a DECLARATION bag, and {@see ResponseDraft::freeze()}
 * publishes a declaration over an illustration whichever ran first. Examples go on the MEDIA TYPE and
 * never into the schema: the shared-error hoist keys on the schema, so recorded there, one route
 * acquiring a recording could drop an unrelated route's 404 out of its shared component. On the media
 * type an error status needs no special case — {@see SharedErrorResponses} lifts an `examples` map into
 * the shared component under the names it carries, so a name recorded on a 404 publishes there.
 */
final class RecordedExamplesExtension implements OperationExtension
{
    public function __construct(
        private readonly string $basePath,
        private readonly ExampleRedaction $redaction = new ExampleRedaction,
    ) {}

    public function phase(): OperationPhase
    {
        // After the response bodies exist, so an example is only ever attached beside a documented
        // schema. Nothing here depends on running before or after the attribute layer: which of the two
        // bags publishes is the draft's answer, not the pipeline's.
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $store = RecordingStore::for($context->document, $this->basePath);
        $operationId = $context->operationId;

        if ($store === null || $operationId === null) {
            return;
        }

        $path = $store->pathFor($operationId);

        if ($path === null) {
            return;
        }

        // The file shapes the emitted bytes, so it keys the fragment whether or not it exists yet —
        // recording an operation for the first time has to invalidate exactly as re-recording does.
        $context->dependencies()->addFile($path);

        $recording = $store->read($operationId);

        if ($recording === null) {
            return;
        }

        foreach (self::slots($recording) as $examples) {
            $this->attach($operation, $examples);
        }
    }

    /**
     * The recording's examples grouped by the media type they illustrate, in the file's own key order.
     *
     * @return list<non-empty-list<RecordedExample>>
     */
    private static function slots(ExampleRecording $recording): array
    {
        $slots = [];

        foreach ($recording->responses as $example) {
            $slots[$example->slot()][] = $example;
        }

        return array_values($slots);
    }

    /**
     * @param  non-empty-list<RecordedExample>  $examples  every example recorded for one media type,
     *                                                     named or unnamed but never a mix of the two
     */
    private function attach(OperationDraft $operation, array $examples): void
    {
        $first = $examples[0];

        if (! $operation->hasResponse($first->status)) {
            return;
        }

        $response = $operation->response($first->status);

        if (! $response->hasContent($first->mediaType)) {
            return;
        }

        // A committed body that still looks like it holds a credential is not published at all. The
        // recorder redacts on the way out, so reaching here means the file was edited by hand or the
        // heuristics have learned something since — either way the safe answer is no example, and
        // {@see RecordedExampleAudit} is what tells the author about it.
        $safe = array_values(array_filter(
            $examples,
            fn (RecordedExample $example): bool => $this->redaction->findings($example->body) === [],
        ));

        if ($safe === []) {
            return;
        }

        if (! $first->isNamed()) {
            // First writer wins here, which is what settles a recording against another integration-layer
            // producer that already illustrated this media type — the built-in error tiers attach the
            // literals they folded, and a value the application really returns is not better evidence than
            // a value the application's own code spells out.
            $response->setExample($first->mediaType, self::best($safe)->body);

            return;
        }

        $named = [];
        foreach ($safe as $example) {
            $named[$example->name] = ['value' => $example->body];
        }

        $response->illustrateExamples($first->mediaType, $named);
    }

    /**
     * @param  non-empty-list<RecordedExample>  $examples
     */
    private static function best(array $examples): RecordedExample
    {
        $best = $examples[0];

        foreach ($examples as $example) {
            if ($example->outranks($best)) {
                $best = $example;
            }
        }

        return $best;
    }
}
