<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Workflows;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Document\Workflow\StepBody;
use Docuccino\Core\Document\Workflow\StepParameter;
use Docuccino\Core\Document\Workflow\Workflow;
use Docuccino\Core\Document\Workflow\WorkflowExtension;
use Docuccino\Core\Document\Workflow\WorkflowStep;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Turns what each operation said about the workflows it takes part in into the document's
 * `x-docuccino.workflows`, which the Arazzo emitter publishes.
 *
 * **The observation rides the fragment and the verdict does not**, the rule {@see UnusableBodyDeclarations}
 * states: a step is recorded per route ({@see DeclaredSteps}) and the SEQUENCE is reconciled here, as a
 * pure function of the fragment set. A warm build reconstructs the same workflows rather than replaying
 * them, so it publishes what a cold build publishes.
 *
 * **The order is the authors', never the discovery order.** Steps sort by the `order` each declared and
 * then by step id, so adding an unrelated route cannot move anybody's sequence. Two steps claiming one
 * position is the one case that cannot be settled from the declarations, and it says so.
 *
 * A step whose operation this document does not publish is dropped in silence. That is the multi-document
 * case rather than a mistake — one workflow's steps can live in documents that do not overlap — and a
 * warning there would fire on every build of every application that splits its routes.
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class WorkflowAssembly implements DocumentTransformer, RouteNoteCollector
{
    /** What a workflow id and a step id may be spelled with, which is Arazzo's own character set. */
    private const string NAME = '/^[A-Za-z0-9_-]+$/';

    /** @var array<string, list<array<string, mixed>>> workflow id => the steps routes recorded for it */
    private array $declared = [];

    public function channel(): string
    {
        return DeclaredSteps::CHANNEL;
    }

    public function forget(): void
    {
        $this->declared = [];
    }

    public function collect(string $key, array $values): void
    {
        foreach (DeclaredSteps::decode($values) as $step) {
            $this->declared[$key][] = $step;
        }
    }

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $doc = $document->toArray();
        $published = self::publishedOperations($doc);

        /** @var array<string, mixed> $configured */
        $configured = $context->config->workflows;

        $workflows = [];
        foreach ($this->declared as $id => $steps) {
            $workflow = $this->workflow((string) $id, $steps, $published, $configured, $doc, $context);

            if ($workflow !== null) {
                $workflows[] = $workflow;
            }
        }

        // By id, so the order the document publishes its workflows in is a function of their names
        // rather than of which route the build happened to reach first.
        usort($workflows, static fn (Workflow $a, Workflow $b): int => strcmp($a->id, $b->id));

        foreach (array_keys($configured) as $id) {
            if (! isset($this->declared[(string) $id])) {
                $context->report(self::describesNothing((string) $id));
            }
        }

        if ($workflows === []) {
            return;
        }

        $document->replace(self::write($doc, $workflows));
    }

    /**
     * One workflow, or null where nothing it declared survived — every step named an operation this
     * document does not publish, which is the multi-document case rather than a mistake.
     *
     * @param  list<array<string, mixed>>  $declared
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $published  signature => [node id, operation]
     * @param  array<string, mixed>  $configured
     * @param  array<string, mixed>  $doc  the document around them, for resolving a `$ref` an output reads through
     */
    private function workflow(string $id, array $declared, array $published, array $configured, array $doc, DocumentContext $context): ?Workflow
    {
        if (preg_match(self::NAME, $id) !== 1) {
            $context->report(self::unnameable('workflow', $id));

            return null;
        }

        $steps = [];
        $taken = [];

        foreach ($declared as $step) {
            $reason = $step[DeclaredSteps::UNREADABLE] ?? null;

            if (is_string($reason)) {
                $context->report(self::unreadableStep($id, is_string($step['route'] ?? null) ? $step['route'] : '', $reason));
            }
        }

        foreach (self::ordered(self::steps($declared)) as $step) {
            $signature = is_string($step['route'] ?? null) ? $step['route'] : '';
            $site = $published[$signature] ?? null;

            if ($site === null) {
                continue;
            }

            [$node, $operation] = $site;

            $stepId = self::stepId($step, $operation);

            if (preg_match(self::NAME, $stepId) !== 1) {
                $context->report(self::unnameable('step', $stepId));

                continue;
            }

            if (isset($taken[$stepId])) {
                $context->report(self::duplicateStep($id, $stepId));

                continue;
            }

            $taken[$stepId] = true;
            $steps[] = self::step($stepId, $step, $node, $operation, $id, $doc, $context);
        }

        if ($steps === []) {
            return null;
        }

        self::reportContestedOrder($id, $declared, $published, $context);
        self::reportDanglingOutputs($id, $steps, $context);

        /** @var array<string, mixed> $bag */
        $bag = is_array($configured[$id] ?? null) ? $configured[$id] : [];

        return new Workflow(
            id: $id,
            steps: $steps,
            summary: self::text($bag, 'summary'),
            description: self::text($bag, 'description'),
            inputs: self::map($bag['inputs'] ?? null),
            outputs: self::expressions($bag['outputs'] ?? null),
        );
    }

    /**
     * One step, with its parameters resolved against the operation as published — the location comes
     * from the operation's own declaration, because a parameter's `in` is a fact about the operation
     * rather than something an author should have to repeat.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     */
    private static function step(string $stepId, array $step, string $node, array $operation, string $workflow, array $doc, DocumentContext $context): WorkflowStep
    {
        $locations = self::parameterLocations($operation);

        $parameters = [];
        foreach (is_array($step['parameters'] ?? null) ? $step['parameters'] : [] as $name => $value) {
            $name = (string) $name;
            $in = $locations[$name] ?? null;

            if ($in === null) {
                $context->report(self::parameterUndeclared($workflow, $stepId, $name));

                continue;
            }

            $parameters[] = new StepParameter($name, $in, $value);
        }

        $payload = is_array($step['body'] ?? null) ? $step['body'] : [];
        $description = is_string($step['description'] ?? null) ? $step['description'] : '';

        $outputs = self::expressions($step['outputs'] ?? null);

        foreach ($outputs as $name => $expression) {
            $undocumented = ResponsePointer::undocumented($operation, $doc, $expression);

            if ($undocumented !== null) {
                $context->report(self::outputUndocumented($workflow, $stepId, $name, $undocumented));
            }
        }

        return new WorkflowStep(
            id: $stepId,
            operation: $node,
            description: $description === '' ? null : $description,
            parameters: $parameters,
            body: $payload === [] ? null : new StepBody(
                is_string($step['contentType'] ?? null) && $step['contentType'] !== '' ? $step['contentType'] : 'application/json',
                $payload,
            ),
            outputs: $outputs,
        );
    }

    /**
     * The entries that are steps, as opposed to the markers left where a declaration could not be
     * carried at all ({@see DeclaredSteps::encode()}).
     *
     * @param  list<array<string, mixed>>  $declared
     * @return list<array<string, mixed>>
     */
    private static function steps(array $declared): array
    {
        $steps = array_values(array_filter(
            $declared,
            static fn (array $entry): bool => ! is_string($entry[DeclaredSteps::UNREADABLE] ?? null),
        ));

        return self::perDeclaration($steps);
    }

    /**
     * One entry per DECLARATION, not per route that reached it.
     *
     * An action bound to more than one route records the same `#[WorkflowStep]` once per route, and
     * they are identical but for the route. Left as several, one attribute produces "two steps are
     * both called read" and "2 steps both declare order 2" — reports whose remedy is to edit a second
     * declaration that does not exist, which is the diagnostic that teaches people to stop reading the
     * channel. So they collapse, and the survivor is chosen by route signature rather than by which
     * route the build reached first.
     *
     * Two DIFFERENT declarations colliding is a real mistake and still reported: they differ somewhere,
     * so they do not collapse.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function perDeclaration(array $steps): array
    {
        $byDeclaration = [];

        foreach ($steps as $step) {
            $declaration = $step;
            unset($declaration['route']);

            $key = (string) json_encode($declaration);
            $route = is_string($step['route'] ?? null) ? $step['route'] : '';

            if (! isset($byDeclaration[$key]) || $route < $byDeclaration[$key]['route']) {
                $byDeclaration[$key] = ['route' => $route, 'step' => $step];
            }
        }

        return array_values(array_map(
            static fn (array $held): array => $held['step'],
            $byDeclaration,
        ));
    }

    /**
     * The declared steps in the order they run: by the position each stated, then by step id. Never by
     * the order they were collected in — that is the order routes were discovered, and a sequence that
     * moves when an unrelated route is added is the defect this argument exists to prevent.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $steps): array
    {
        usort($steps, static function (array $a, array $b): int {
            $order = (is_int($a['order'] ?? null) ? $a['order'] : 0) <=> (is_int($b['order'] ?? null) ? $b['order'] : 0);

            return $order !== 0
                ? $order
                : strcmp(is_string($a['id'] ?? null) ? $a['id'] : '', is_string($b['id'] ?? null) ? $b['id'] : '');
        });

        return $steps;
    }

    /**
     * What later steps call this one: what the author wrote, or the operation's own published
     * `operationId` with the characters Arazzo does not take replaced. Minted from the operation rather
     * than from a counter, so it is a function of the thing and adding a step renames nothing.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $operation
     */
    private static function stepId(array $step, array $operation): string
    {
        $declared = is_string($step['id'] ?? null) ? trim($step['id']) : '';

        if ($declared !== '') {
            return $declared;
        }

        $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : '';

        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $operationId);
    }

    /**
     * Every operation the document publishes, by the signature a step names it with — and paired with
     * the node id THIS document minted for it.
     *
     * Keyed on the signature rather than on the id because that is the only thing a note may carry: a
     * fragment is shared between documents that build alike, so an id recorded in one document's build
     * would be the wrong id in the other's. Looking it up here is what makes a step point at the
     * operation of the document being assembled.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private static function publishedOperations(array $doc): array
    {
        $operations = [];

        foreach (DocumentGraph::operationSites($doc) as $site) {
            $operation = DocumentGraph::at($doc, $site['keys']);
            $signature = $site['signature'];

            if (! is_array($operation) || $signature === null) {
                continue;
            }

            $docuccino = $operation['x-docuccino'] ?? null;
            $id = is_array($docuccino) ? $docuccino['id'] ?? null : null;

            if (is_string($id) && $id !== '') {
                /** @var array<string, mixed> $operation */
                $operations[$signature] = [$id, $operation];
            }
        }

        return $operations;
    }

    /**
     * Parameter name => where it travels, as the operation declares it.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, string>
     */
    private static function parameterLocations(array $operation): array
    {
        $locations = [];

        foreach (is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [] as $parameter) {
            if (is_array($parameter) && is_string($parameter['name'] ?? null) && is_string($parameter['in'] ?? null)) {
                $locations[$parameter['name']] = $parameter['in'];
            }
        }

        return $locations;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<Workflow>  $workflows
     * @return array<string, mixed>
     */
    private static function write(array $doc, array $workflows): array
    {
        $extension = is_array($doc['x-docuccino'] ?? null) ? $doc['x-docuccino'] : [];
        $extension['workflows'] = (new WorkflowExtension($workflows))->toArray();
        $doc['x-docuccino'] = $extension;

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function text(array $bag, string $key): ?string
    {
        $value = $bag[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A nested bag as a JSON object: keys are strings, whatever the reader handed back.
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $member) {
            $out[(string) $key] = $member;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function expressions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $expression) {
            if (is_string($expression) && $expression !== '') {
                $out[(string) $name] = $expression;
            }
        }

        return $out;
    }

    /**
     * Two steps of one workflow claiming one position. Reported rather than settled, because the tie is
     * broken by step id to keep the build deterministic and that answer is arbitrary — the author is the
     * only one who knows which runs first.
     *
     * @param  list<array<string, mixed>>  $declared
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $published
     */
    private static function reportContestedOrder(string $workflow, array $declared, array $published, DocumentContext $context): void
    {
        $seen = [];

        foreach (self::steps($declared) as $step) {
            $signature = is_string($step['route'] ?? null) ? $step['route'] : '';

            if (! isset($published[$signature])) {
                continue;
            }

            $order = is_int($step['order'] ?? null) ? $step['order'] : 0;
            $seen[$order] = ($seen[$order] ?? 0) + 1;
        }

        foreach ($seen as $order => $count) {
            if ($count > 1) {
                $context->report(new Diagnostic(
                    severity: Severity::Warning,
                    code: 'workflow.order-contested',
                    message: sprintf(
                        '%d steps of the workflow "%s" both declare order %d, so which runs first is not something the declarations settle.',
                        $count,
                        $workflow,
                        $order,
                    ),
                    help: 'Give each step of a workflow its own `order`. They were published in step-id order to keep the build deterministic, which is an answer nobody chose.',
                ));
            }
        }
    }

    /**
     * A step reading an output no earlier step produces. The one report that makes authoring a workflow
     * across several files safe: nothing else notices that the step the expression names was deleted,
     * renamed, or simply never wrote that output — and an Arazzo runner meets it as a null at run time.
     *
     * @param  list<WorkflowStep>  $steps
     */
    private static function reportDanglingOutputs(string $workflow, array $steps, DocumentContext $context): void
    {
        // Only a step THIS document publishes can be judged. A reference naming one it does not is the
        // split-document case — one workflow's steps can live in documents that do not overlap — and a
        // report there fires on every build of every application that splits its routes, which is the
        // population this class promises silence for. What stays reportable is the half an author can
        // act on: a step that is here and produces no such output, or produces it later.
        $here = [];

        foreach ($steps as $step) {
            $here[$step->id] = true;
        }

        $available = [];

        foreach ($steps as $step) {
            foreach (self::references($step) as $reference) {
                if (! isset($here[self::referencedStep($reference)])) {
                    continue;
                }

                if (! isset($available[$reference])) {
                    $context->report(new Diagnostic(
                        severity: Severity::Warning,
                        code: 'workflow.output-unresolved',
                        message: sprintf(
                            'The step "%s" of the workflow "%s" reads `%s`, which no earlier step of it produces.',
                            $step->id,
                            $workflow,
                            $reference,
                        ),
                        help: 'An output is readable only after the step that declares it has run. Check the output name, and that the step producing it declares that output and comes earlier in the order.',
                    ));
                }
            }

            foreach (array_keys($step->outputs) as $name) {
                $available['$steps.'.$step->id.'.outputs.'.$name] = true;
            }
        }
    }

    /** The step a `$steps.<id>.outputs.<name>` reference names. */
    private static function referencedStep(string $reference): string
    {
        return explode('.', $reference)[1] ?? '';
    }

    /**
     * Every `$steps.<id>.outputs.<name>` a step reads, in its parameters and in its body.
     *
     * @return list<string>
     */
    private static function references(WorkflowStep $step): array
    {
        $values = array_map(static fn (StepParameter $parameter): mixed => $parameter->value, $step->parameters);

        if ($step->body !== null) {
            $values[] = $step->body->payload;
        }

        $found = [];
        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            if (is_string($value) && preg_match_all('/\$steps\.[A-Za-z0-9_-]+\.outputs\.[A-Za-z0-9_-]+/', $value, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $found[$match] = true;
                }
            }
        });

        return array_keys($found);
    }

    private static function unnameable(string $kind, string $name): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.name-unusable',
            message: sprintf(
                'The %s "%s" is not a name Arazzo can carry, so it was left out of the document.',
                $kind,
                $name,
            ),
            help: 'Arazzo takes letters, digits, `_` and `-` in a workflowId and a stepId. Rename it, or give the step an `id:` of its own where the one minted from the operation is the problem.',
        );
    }

    private static function duplicateStep(string $workflow, string $stepId): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.step-id-repeated',
            message: sprintf(
                'Two steps of the workflow "%s" are both called "%s", so the second was left out — an expression naming it could only mean one of them.',
                $workflow,
                $stepId,
            ),
            help: 'Give one of them an `id:` of its own. Two steps calling the same operation mint the same id by default, which is when this happens.',
        );
    }

    private static function parameterUndeclared(string $workflow, string $stepId, string $name): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.parameter-undeclared',
            message: sprintf(
                'The step "%s" of the workflow "%s" passes "%s", which its operation declares no parameter of that name, so nothing says where the value travels and it was left out.',
                $stepId,
                $workflow,
                $name,
            ),
            help: 'Name the parameter the way the operation declares it — a step supplies a parameter the operation already has, and its location is read from there rather than repeated.',
        );
    }

    /**
     * A step reading a member of a response the operation does not document. The claim is about the
     * DOCUMENT rather than the wire — a schema that names properties may still carry others — and it is
     * worth making for that reason: a workflow output is a promise to a consumer, and a field the
     * response schema does not describe is one no generated client has.
     */
    private static function outputUndocumented(string $workflow, string $stepId, string $name, string $pointer): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.output-undocumented',
            message: sprintf(
                'The step "%s" of the workflow "%s" reads its output "%s" from `%s`, which its operation\'s response does not document.',
                $stepId,
                $workflow,
                $name,
                $pointer,
            ),
            help: 'Point at a member the response schema describes, or document the member — a workflow output a consumer cannot find in the response is a promise the document does not keep. Nothing is reported where the response describes no shape to contradict.',
        );
    }

    /**
     * A step the build could not carry from the declaration to the document. Reported rather than
     * dropped: a workflow that quietly published a shorter sequence would be telling a consumer that
     * these calls get them there while leaving one out.
     */
    private static function unreadableStep(string $workflow, string $route, string $reason): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.step-unreadable',
            message: sprintf(
                'A step of the workflow "%s" declared on %s could not be read, so the workflow publishes without it (%s).',
                $workflow,
                $route === '' ? 'an operation' : $route,
                $reason,
            ),
            help: 'A step is carried as JSON, so its `parameters` and `body` have to be values JSON can hold — scalars, arrays of them, and the runtime expressions. A pure enum case or a non-UTF-8 string is the usual cause; write the value the wire carries instead.',
        );
    }

    private static function describesNothing(string $id): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'workflow.describes-nothing',
            message: sprintf(
                'The configuration describes a workflow "%s" that no operation declares a step of, so nothing carries the description.',
                $id,
            ),
            help: 'Config enriches a workflow that #[WorkflowStep] declares; it does not create one. Check the spelling, or retire the entry if the workflow is gone.',
        );
    }
}
