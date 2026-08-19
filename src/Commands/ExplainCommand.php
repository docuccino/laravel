<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\FieldTrail;
use Docuccino\Core\Provenance\Explain\OperationExplainer;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Routing\OperationLookup;
use Docuccino\Laravel\Routing\OperationMatch;
use Docuccino\Laravel\Support\ConsoleTable;
use Docuccino\Laravel\Support\ProvenanceReport;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers "why is this endpoint documented this way?" for one operation, by reading the provenance
 * trail the build already wrote: what each layer contributed field by field, what it overrode, where
 * it came from, how sure it was — and what to change to override it.
 *
 * It builds the document rather than reading an artifact, so the trail it reads is always the full
 * one — `--provenance` levels only decide how much of it survives into an exported file, and an
 * artifact exported at `winners` or `none` has already thrown away the answer. Nothing is written and
 * no cache is touched: the run is a read of a document built in memory.
 *
 * Three outcomes, three exit codes: explained (0), nothing matched (1), several matched (2). Which
 * one a query gets is the {@see OperationLookup}'s call, and it never picks a match on the reader's
 * behalf. `--field` narrows the same way and answers on the same three codes.
 */
final class ExplainCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;
    use PrintsSections;
    use StringOptions;

    protected $signature = 'docuccino:explain
        {route : The operation — "POST /api/invoices", a URI, a route name, an operation id, or part of any of them}
        {document? : The configured document key (defaults to every document)}
        {--method= : Narrow a URI several verbs answer (get, post, put, patch, delete, …)}
        {--field= : Explain one field, printing every value in full (e.g. requestBody, responses.201.description)}
        {--json : Print the trail as JSON instead of the report}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Explain why one endpoint is documented the way it is, layer by layer.';

    public function __construct(
        private readonly OperationExplainer $explainer = new OperationExplainer,
        private readonly ProvenanceReport $report = new ProvenanceReport,
    ) {
        parent::__construct();
    }

    public function handle(DocumentBuilder $builder, TypeEngine $engine, Router $router): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $keys = $this->selectedDocuments($builder);
        $method = $this->methodOption();
        if ($keys === null || $method === false) {
            return self::FAILURE;
        }

        $lookup = new OperationLookup($router);
        $documents = [];
        $operations = [];

        foreach ($keys as $key) {
            $documents[$key] = $builder->build($key, $engine)->document->toArray();
            $operations = [...$operations, ...$lookup->operations($key, $documents[$key])];
        }

        $query = $this->query();
        $matches = $lookup->match($operations, $query, $method);

        if ($matches === []) {
            return $this->reportNothing($query, $operations);
        }

        if (count($matches) > 1) {
            return $this->reportSeveral($query, $matches);
        }

        $match = $matches[0];
        $nodes = $this->explainer->explain($documents[$match->document], $match->path, $match->method);

        $field = $this->stringOption('field');

        return $field === null
            ? $this->explain($match, $nodes)
            : $this->explainField($match, $nodes, $field);
    }

    /**
     * @param  list<ExplainedNode>  $nodes
     */
    private function explain(OperationMatch $match, array $nodes): int
    {
        if ($this->option('json') === true) {
            $this->json([
                'status' => 'explained',
                'operation' => $match->toArray(),
                'nodes' => array_map(static fn (ExplainedNode $node): array => $node->toArray(), $nodes),
            ]);

            return self::SUCCESS;
        }

        $this->section($match->signature(), $this->meta($match));

        if ($nodes === []) {
            $this->emptyTrail();

            return self::SUCCESS;
        }

        // The ladder is the answer to "why did mine lose", so it is printed where something lost and
        // nowhere else — on an uncontested operation it explains a competition that never happened.
        if ($this->report->contested($nodes)) {
            $this->newLine();
            foreach ($this->report->legend() as $line) {
                $this->line($line);
            }
        }

        foreach ($this->report->lines($nodes) as $line) {
            $this->line($line);
        }

        $this->newLine();
        foreach ($this->report->summary($nodes) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * One field, every value in full. Narrowed exactly as the route argument is — an exact path, then
     * an exact name, then a fragment — and answering on the same three exit codes, so a query that
     * names several fields lists them rather than picking one.
     *
     * @param  list<ExplainedNode>  $nodes
     */
    private function explainField(OperationMatch $match, array $nodes, string $query): int
    {
        $found = self::matchFields($nodes, $query);

        if ($found === [] || count($found) > 1) {
            return $this->reportFields($match, $nodes, $query, $found);
        }

        [$node, $trail] = $found[0];

        if ($this->option('json') === true) {
            $this->json([
                'status' => 'explained',
                'operation' => $match->toArray(),
                'nodes' => [(new ExplainedNode($node->label, $node->pointer, [$trail], $node->ref))->toArray()],
            ]);

            return self::SUCCESS;
        }

        $this->section($match->signature(), $this->meta($match));

        foreach ($this->report->field($node, $trail) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Nothing matched, or too much did. Either way the answer is the field paths this operation
     * actually has, in the same layout the ambiguous-route list uses — unless it has none, where the
     * honest answer is the empty trail rather than an empty list.
     *
     * @param  list<ExplainedNode>  $nodes
     * @param  list<array{0: ExplainedNode, 1: FieldTrail}>  $found
     */
    private function reportFields(OperationMatch $match, array $nodes, string $query, array $found): int
    {
        $listed = $found === [] ? self::allFields($nodes) : $found;
        $status = $found === [] ? 'no-match' : 'ambiguous';
        $exit = $found === [] ? self::FAILURE : self::INVALID;

        if ($this->option('json') === true) {
            $this->json([
                'status' => $status,
                'query' => $query,
                'operation' => $match->toArray(),
                'fields' => array_map(static fn (array $pair): string => self::path($pair[0], $pair[1]), $listed),
            ]);

            return $exit;
        }

        $this->section(
            $found === []
                ? sprintf('No field matches "%s" on %s.', $query, $match->signature())
                : sprintf('%d fields match "%s".', count($found), $query),
            null,
        );

        // No fields to list at all, so the query did not miss — the operation recorded nothing, and
        // a header rule over no rows plus a command naming a field that does not exist would say it
        // did.
        if ($listed === []) {
            $this->emptyTrail();

            return $exit;
        }

        $this->newLine();

        foreach (ConsoleTable::render(['Field', 'Rung'], array_map(static fn (array $pair): array => [
            self::path($pair[0], $pair[1]),
            $pair[1]->winner()?->layer->label() ?? '—',
        ], $listed)) as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>php artisan docuccino:explain "%s" --field=%s</>',
            TerminalText::of($match->signature()),
            TerminalText::of(self::path($listed[0][0], $listed[0][1])),
        ));

        return $exit;
    }

    /**
     * Nothing matched. The reader typed something, so the answer is the vocabulary they could have
     * typed instead — spelled with an operation this document really has, rather than an invented one.
     *
     * @param  list<OperationMatch>  $operations
     */
    private function reportNothing(string $query, array $operations): int
    {
        if ($this->option('json') === true) {
            $this->json(['status' => 'no-match', 'query' => $query, 'matches' => []]);

            return self::FAILURE;
        }

        $this->section(sprintf('No operation matches "%s".', $query), null);

        $example = $operations[0] ?? null;
        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>%d operation%s published. Name one by:</>',
            count($operations),
            count($operations) === 1 ? ' is' : 's are',
        ));

        foreach ($this->spellings($example) as $label => $spelling) {
            $this->line(sprintf('  %-14s <fg=gray>php artisan docuccino:explain %s</>', $label, TerminalText::of($spelling)));
        }

        $this->newLine();
        $this->line('<fg=gray>An endpoint missing altogether never reached this document — check the</>');
        $this->line('<fg=gray>routes.include / routes.exclude patterns and #[ExcludeFromDocs].</>');

        return self::FAILURE;
    }

    /**
     * Several matched. Choosing one for the reader would be a guess, so they are listed with every
     * name they answer to and the exit code says the query was not specific enough.
     *
     * @param  list<OperationMatch>  $matches
     */
    private function reportSeveral(string $query, array $matches): int
    {
        if ($this->option('json') === true) {
            $this->json([
                'status' => 'ambiguous',
                'query' => $query,
                'matches' => array_map(static fn (OperationMatch $match): array => $match->toArray(), $matches),
            ]);

            return self::INVALID;
        }

        $this->section(sprintf('%d operations match "%s".', count($matches), $query), null);
        $this->newLine();

        foreach (ConsoleTable::render(
            ['Method', 'URI', 'Document', 'Route', 'Operation id'],
            array_map(static fn (OperationMatch $match): array => [
                strtoupper($match->method),
                $match->path,
                $match->document,
                $match->name ?? '—',
                $match->operationId ?? '—',
            ], $matches),
        ) as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line(sprintf('<fg=gray>php artisan docuccino:explain "%s"</>', TerminalText::of($matches[0]->signature())));

        return self::INVALID;
    }

    /** The trail is empty. Say why that is not a missing flag, so nobody goes looking for one. */
    private function emptyTrail(): void
    {
        $this->newLine();
        $this->line('No provenance recorded for this operation.');
        $this->newLine();
        $this->line('<fg=gray>Every build records the whole trail — `--provenance` only decides how much of it</>');
        $this->line('<fg=gray>survives into an exported artifact, and this command builds its own document. So</>');
        $this->line('<fg=gray>nothing wrote a field here through the precedence guard: an action that could not</>');
        $this->line('<fg=gray>be reflected is documented as a skeleton, and a skeleton has nothing to explain.</>');
    }

    /**
     * The fields a `--field` query names: an exact `label.field` path, then an exact field name, then
     * a fragment of either. Same ladder as the route argument, for the same reason.
     *
     * @param  list<ExplainedNode>  $nodes
     * @return list<array{0: ExplainedNode, 1: FieldTrail}>
     */
    private static function matchFields(array $nodes, string $query): array
    {
        $all = self::allFields($nodes);
        $needle = mb_strtolower(trim($query));

        if ($needle === '') {
            return [];
        }

        $tiers = [
            static fn (ExplainedNode $node, FieldTrail $trail): bool => mb_strtolower(self::path($node, $trail)) === $needle,
            static fn (ExplainedNode $node, FieldTrail $trail): bool => mb_strtolower($trail->field) === $needle,
            static fn (ExplainedNode $node, FieldTrail $trail): bool => str_contains(mb_strtolower(self::path($node, $trail)), $needle),
        ];

        foreach ($tiers as $matches) {
            $found = array_values(array_filter($all, static fn (array $pair): bool => $matches($pair[0], $pair[1])));

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @param  list<ExplainedNode>  $nodes
     * @return list<array{0: ExplainedNode, 1: FieldTrail}>
     */
    private static function allFields(array $nodes): array
    {
        $all = [];

        foreach ($nodes as $node) {
            foreach ($node->fields as $trail) {
                $all[] = [$node, $trail];
            }
        }

        return $all;
    }

    /** How a reader names one field of one node: `responses.201.description`. */
    private static function path(ExplainedNode $node, FieldTrail $trail): string
    {
        return $node->label === 'operation' ? $trail->field : $node->label.'.'.$trail->field;
    }

    /**
     * The spellings the command accepts, each shown against a real operation where there is one.
     *
     * @return array<string, string>
     */
    private function spellings(?OperationMatch $example): array
    {
        $signature = $example === null ? 'POST /api/invoices' : $example->signature();
        $path = $example === null ? '/api/invoices' : $example->path;

        return [
            'method + URI' => '"'.$signature.'"',
            'URI' => ltrim($path, '/'),
            'route name' => $example->name ?? 'invoices.store',
            'operation id' => $example->operationId ?? 'storeInvoice',
            'any fragment' => self::distinctive($path),
        ];
    }

    /** The most specific literal segment of a path — the half of it worth typing as a fragment. */
    private static function distinctive(string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && ! str_contains($segment, '{'),
        ));

        return $segments === [] ? 'invoices' : $segments[count($segments) - 1];
    }

    private function meta(OperationMatch $match): string
    {
        $parts = [];

        $action = $match->shortAction();
        if ($action !== null) {
            $parts[] = $action;
        }

        $parts[] = sprintf('document "%s"', $match->document);

        if ($match->name !== null) {
            $parts[] = 'route '.$match->name;
        }

        if ($match->operationId !== null) {
            $parts[] = 'operationId '.$match->operationId;
        }

        return implode('  ·  ', $parts);
    }

    /**
     * Raw, like the diff's JSON: `line()` writes at OUTPUT_NORMAL, where the formatter would read a
     * `<…>` inside an application's own value as markup and drop it — still valid JSON, and no longer
     * the data the tool reading it is deciding on.
     *
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): void
    {
        $this->output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
    }

    private function query(): string
    {
        $route = $this->argument('route');

        return is_string($route) ? trim($route) : '';
    }

    /** The `--method` filter, or false having printed why it is not one. */
    private function methodOption(): string|false|null
    {
        $method = $this->stringOption('method');
        if ($method === null) {
            return null;
        }

        $method = strtolower($method);
        if (in_array($method, PathItem::METHODS, true)) {
            return $method;
        }

        $this->error(sprintf(
            'Unknown --method "%s"; expected one of: %s.',
            TerminalText::of($method),
            implode(', ', PathItem::METHODS),
        ));

        return false;
    }
}
