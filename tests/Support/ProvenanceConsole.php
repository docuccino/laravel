<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\FieldTrail;
use Docuccino\Laravel\Support\ProvenanceReport;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders a {@see ProvenanceReport} the way a terminal receives it, so the report can be read off the
 * bytes a real Symfony formatter emits rather than off the markup handed to it — whether a rung's
 * colour survives, and whether the same line still says everything with the colour switched off, are
 * both questions about those bytes.
 */
final class ProvenanceConsole
{
    /**
     * @param  list<ExplainedNode>  $nodes
     */
    public static function body(array $nodes, bool $decorated = false): string
    {
        return self::format((new ProvenanceReport)->lines($nodes), $decorated);
    }

    public static function legend(bool $decorated = false): string
    {
        return self::format((new ProvenanceReport)->legend(), $decorated);
    }

    /**
     * @param  list<ExplainedNode>  $nodes
     */
    public static function summary(array $nodes): string
    {
        return self::format((new ProvenanceReport)->summary($nodes), false);
    }

    /** One field with every value in full — the `--field` form. */
    public static function field(ExplainedNode $node, FieldTrail $trail, bool $decorated = false): string
    {
        return self::format((new ProvenanceReport)->field($node, $trail), $decorated);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function format(array $lines, bool $decorated): string
    {
        $formatter = new OutputFormatter($decorated);

        return implode("\n", array_map(static fn (string $line): string => $formatter->format($line), $lines));
    }
}
