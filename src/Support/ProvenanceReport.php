<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\FieldContribution;
use Docuccino\Core\Provenance\Explain\FieldTrail;
use Docuccino\Core\Provenance\Source;

/**
 * Renders a provenance trail as a stack per field: the value the document publishes on top, every
 * value a lower rung lost with under it, and one line saying how to change it. One colour and one
 * written-out name per precedence layer, used identically everywhere, so a reader learns the ladder
 * off one screen.
 *
 * Colour never carries meaning on its own — a mark, an indent and the layer's NAME say the same
 * thing — so the report reads the same piped to a file, under `--no-ansi` and in a CI log.
 *
 * Nothing here consults the terminal width: the same document renders the same bytes on every
 * machine, which is what makes the output snapshot-testable. Long values are elided against a fixed
 * budget instead — and `--field` prints one in full, so the elision is never the end of the road. A
 * `file:line` is never elided or wrapped, because opening it is the reader's next move.
 *
 * @internal
 */
final class ProvenanceReport
{
    /** Wide enough for `integration`, the longest layer name, so the marks line up under each other. */
    private const int LAYER_WIDTH = 11;

    /** How much of a value is worth showing before it stops being scannable. */
    private const int VALUE_WIDTH = 56;

    /**
     * A mapper reports 0.9 for a conversion that fully succeeded, which is most of them, so printing
     * every number trains a reader to skip the column. Only a value BELOW it says something.
     */
    private const float CONFIDENCE_FLOOR = 0.9;

    /**
     * The ladder, low to high, with the mark vocabulary under it. Printed only where something was
     * actually contested ({@see contested()}): on an operation nothing shadowed, the ladder explains a
     * competition that never happened, and three lines of chrome ahead of the answer is a cost like
     * any other.
     *
     * @return list<string>
     */
    public function legend(): array
    {
        $rungs = array_map(
            static fn (Layer $layer): string => sprintf('<fg=%s>%s</>', self::colour($layer), $layer->label()),
            Layer::cases(),
        );

        return [
            '<fg=gray>Precedence, low to high — the highest rung that writes a field wins it:</>',
            implode('<fg=gray> › </>', $rungs),
            '<fg=green>✓</> published    <fg=gray>✗ shadowed</>',
        ];
    }

    /**
     * Whether any field on this operation was written by more than one rung.
     *
     * @param  list<ExplainedNode>  $nodes
     */
    public function contested(array $nodes): bool
    {
        foreach ($nodes as $node) {
            foreach ($node->fields as $trail) {
                if ($trail->isContested()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<ExplainedNode>  $nodes
     * @return list<string>
     */
    public function lines(array $nodes): array
    {
        $lines = [];

        foreach ($nodes as $node) {
            $lines[] = '';
            $lines[] = $this->nodeLine($node);

            // One integration usually writes a whole parameter or response from one place, and
            // repeating that line — or the same remedy — under every field of it buries the fields.
            // Where the node has exactly one story to tell, it is told once at the top instead.
            //
            // A node where something LOST has more than one story by definition, and a `from` line
            // above a `✗` row would look like it spoke for that row too — which it never can, since a
            // shadowed contribution records no source at all. Such a node keeps every line beside the
            // stack it belongs to, and so does one whose sources differ.
            $source = $this->shadowed($node) ? null : $this->sharedLine($node, fn (FieldTrail $t, FieldContribution $c): ?string => $this->detail($c));
            $remedy = $source === null ? null : $this->sharedLine($node, static fn (FieldTrail $t, FieldContribution $c): string => OverrideHint::for($node->label, $t->field, $c->layer));

            if ($source !== null) {
                $lines[] = sprintf('  <fg=gray>from %s</>', $source);
            }

            if ($remedy !== null) {
                $lines[] = sprintf('  <fg=gray>→ %s</>', TerminalText::of($remedy));
            }

            foreach ($node->fields as $trail) {
                $lines = [...$lines, ...$this->trailLines($node, $trail, $source !== null, $remedy !== null)];
            }
        }

        return $lines;
    }

    /**
     * One field's stack with every value printed in full — what `--field` is for. A value the report
     * would have elided is the exact thing the reader came to see, so here it gets its own block
     * rather than a budget.
     *
     * @return list<string>
     */
    public function field(ExplainedNode $node, FieldTrail $trail): array
    {
        $lines = ['', $this->nodeLine($node), '  '.TerminalText::of($trail->field)];

        foreach ($trail->contributions as $contribution) {
            $lines[] = sprintf(
                '    %s <fg=%s>%s</>',
                $contribution->won ? '<fg=green>✓</>' : '<fg=gray>✗</>',
                self::colour($contribution->layer),
                $contribution->layer->label(),
            );

            $detail = $this->detail($contribution);
            if ($detail !== null) {
                $lines[] = sprintf('        <fg=gray>%s</>', $detail);
            }

            foreach ($this->valueBlock($contribution) as $line) {
                $lines[] = $line;
            }
        }

        $winner = $trail->winner();
        if ($winner !== null) {
            $lines[] = '';
            $lines[] = sprintf('  <fg=gray>→ %s</>', TerminalText::of(OverrideHint::for($node->label, $trail->field, $winner->layer)));
        }

        return $lines;
    }

    /**
     * One line counting what the reader just read, so a long report ends somewhere, plus the two
     * things the report could not say inline: that a shadowed value is remembered by producer alone,
     * and that an elided value can be had in full.
     *
     * @param  list<ExplainedNode>  $nodes
     * @return list<string>
     */
    public function summary(array $nodes): array
    {
        $fields = 0;
        $contributions = 0;
        $shadowed = 0;
        $elided = 0;

        foreach ($nodes as $node) {
            foreach ($node->fields as $trail) {
                $fields++;
                foreach ($trail->contributions as $contribution) {
                    $contributions++;
                    $shadowed += $contribution->won ? 0 : 1;
                    $elided += self::isElided($contribution->value) ? 1 : 0;
                }
            }
        }

        $lines = [sprintf(
            '<fg=gray>%d field%s · %d contribution%s · %d shadowed</>',
            $fields,
            $fields === 1 ? '' : 's',
            $contributions,
            $contributions === 1 ? '' : 's',
            $shadowed,
        )];

        if ($shadowed > 0) {
            // Said once rather than as an empty column on every ✗ row: the trail records a displaced
            // value by producer, and keeps no place for the file it came from.
            $lines[] = '<fg=gray>A shadowed value is recorded by producer only — the trail keeps what lost, not where it came from.</>';
        }

        if ($elided > 0) {
            $lines[] = sprintf('<fg=gray>%d value%s shortened to fit — `--field=<name>` prints one in full.</>', $elided, $elided === 1 ? '' : 's');
        }

        return $lines;
    }

    private function nodeLine(ExplainedNode $node): string
    {
        $line = sprintf('<options=bold>%s</>', TerminalText::of($node->label));

        return $node->ref === null
            ? $line
            : $line.sprintf('  <fg=gray>→ %s</>', TerminalText::of($node->ref));
    }

    /**
     * @return list<string>
     */
    private function trailLines(ExplainedNode $node, FieldTrail $trail, bool $sourceHoisted, bool $remedyHoisted): array
    {
        $lines = ['  '.TerminalText::of($trail->field)];

        foreach ($trail->contributions as $contribution) {
            $lines[] = $this->contributionLine($contribution);

            $detail = $sourceHoisted && $contribution->won ? null : $this->detail($contribution);
            if ($detail !== null) {
                $lines[] = sprintf('        <fg=gray>%s</>', $detail);
            }
        }

        $winner = $trail->winner();
        if (! $remedyHoisted && $winner !== null) {
            $lines[] = sprintf('    <fg=gray>→ %s</>', TerminalText::of(OverrideHint::for($node->label, $trail->field, $winner->layer)));
        }

        return $lines;
    }

    private function contributionLine(FieldContribution $contribution): string
    {
        $value = self::value($contribution);

        return sprintf(
            '    %s <fg=%s>%s</> %s',
            $contribution->won ? '<fg=green>✓</>' : '<fg=gray>✗</>',
            self::colour($contribution->layer),
            str_pad($contribution->layer->label(), self::LAYER_WIDTH),
            $contribution->won ? TerminalText::of($value) : sprintf('<fg=gray>%s</>', TerminalText::of($value)),
        );
    }

    /** Whether any contribution on this node lost to a higher rung. */
    private function shadowed(ExplainedNode $node): bool
    {
        foreach ($node->fields as $trail) {
            foreach ($trail->contributions as $contribution) {
                if (! $contribution->won) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The one line every winning contribution on this node shares, or null when they differ — in
     * which case each keeps its own. Unanimity is the whole test, however many fields there are, so
     * two sibling nodes never lay out differently for having a different number of fields.
     *
     * @param  callable(FieldTrail, FieldContribution): ?string  $of
     */
    private function sharedLine(ExplainedNode $node, callable $of): ?string
    {
        $values = [];

        foreach ($node->fields as $trail) {
            foreach ($trail->contributions as $contribution) {
                if (! $contribution->won) {
                    continue;
                }

                // A field with nothing to say counts as disagreement, not as agreement by absence:
                // hoisting a source over a docblock summary that has none would claim it came from
                // there.
                $values[] = $of($trail, $contribution) ?? '';
            }
        }

        $unique = array_values(array_unique($values));

        return count($unique) === 1 && $unique[0] !== '' ? $unique[0] : null;
    }

    /**
     * Where the contribution came from, when there is anything to add: the producer where it names
     * something more specific than its rung, the `file:line` to open, and a confidence only where it
     * is low enough to act on.
     */
    private function detail(FieldContribution $contribution): ?string
    {
        $parts = [];

        if ($contribution->producer !== $contribution->layer->label()) {
            $parts[] = TerminalText::of($contribution->producer);
        }

        $where = self::where($contribution->source);
        if ($where !== null) {
            $parts[] = $where;
        }

        if ($contribution->confidence !== null && $contribution->confidence < self::CONFIDENCE_FLOOR) {
            $parts[] = sprintf('confidence %s', rtrim(rtrim(number_format($contribution->confidence, 2, '.', ''), '0'), '.'));
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The value on its own lines, indented, exactly as written — the `--field` form.
     *
     * @return list<string>
     */
    private function valueBlock(FieldContribution $contribution): array
    {
        if ($contribution->removed) {
            return ['        <fg=gray>(removed by this layer — the field is not in the document)</>'];
        }

        $encoded = json_encode($contribution->value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return ['        <fg=gray>('.gettype($contribution->value).')</>'];
        }

        $lines = [];
        foreach (explode("\n", $encoded) as $line) {
            $lines[] = '        '.TerminalText::of($line);
        }

        return $lines;
    }

    /**
     * `file:line`, then the symbol only where it says something the path does not — a source inside
     * a parent class or a trait, or a pseudo-symbol like `implicit:validated-request`. A
     * `FooController::bar` beside `.../FooController.php` is the path again, and dropping it is what
     * keeps the line short enough to stay one line.
     */
    private static function where(?Source $source): ?string
    {
        if ($source === null || $source->file === '') {
            return null;
        }

        $where = $source->file.($source->line === null ? '' : ':'.$source->line);
        $symbol = $source->symbol;
        $class = basename($source->file, '.php');

        if ($symbol !== null && ! str_starts_with($symbol, $class.'::') && ! str_contains($symbol, '\\'.$class.'::')) {
            $where .= ' · '.$symbol;
        }

        return TerminalText::of($where);
    }

    /** A value as one scannable line: JSON, so a string reads as a string, elided to a fixed budget. */
    private static function value(FieldContribution $contribution): string
    {
        if ($contribution->removed) {
            return '(removed by this layer)';
        }

        $encoded = self::encode($contribution->value);
        if ($encoded === null) {
            return '('.gettype($contribution->value).')';
        }

        return mb_strlen($encoded) <= self::VALUE_WIDTH
            ? $encoded
            : mb_substr($encoded, 0, self::VALUE_WIDTH - 1).'…';
    }

    private static function isElided(mixed $value): bool
    {
        $encoded = self::encode($value);

        return $encoded !== null && mb_strlen($encoded) > self::VALUE_WIDTH;
    }

    private static function encode(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /** The one palette. Written out beside the layer's NAME everywhere, never as the only signal. */
    private static function colour(Layer $layer): string
    {
        return match ($layer) {
            Layer::Fallback => 'gray',
            Layer::Inference => 'cyan',
            Layer::Integration => 'bright-blue',
            Layer::Docblock => 'green',
            Layer::Attribute => 'yellow',
            Layer::Overlay => 'magenta',
            Layer::Config => 'bright-red',
        };
    }
}
