<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * One notice for a rule class nothing could read, where it is on more than one field: annotating the class
 * with `#[RuleSchema]` answers every one of them at once, so the notice names the operations it reached
 * instead of each field printing the same remedy. The class is read off each notice's own `source`, which
 * travels on the route's fragment, so a warm build groups exactly as a cold one does.
 */
final class UnreadRuleNotices
{
    private const CODE = 'validation.rule-unrecoverable';

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    public static function grouped(array $diagnostics): array
    {
        /** @var array<string, list<int>> $byClass */
        $byClass = [];
        foreach ($diagnostics as $index => $diagnostic) {
            $class = $diagnostic->code === self::CODE ? $diagnostic->source?->symbol : null;
            if ($class !== null) {
                $byClass[$class."\0".($diagnostic->help ?? '')][] = $index;
            }
        }

        $out = $diagnostics;
        foreach ($byClass as $members) {
            if (count($members) < 2) {
                continue;
            }

            $first = $diagnostics[$members[0]];
            $routes = [];
            foreach ($members as $index) {
                $routes[$diagnostics[$index]->routeSignature ?? '(document)'] = true;
                unset($out[$index]);
            }

            $operations = array_map(strval(...), array_keys($routes));
            sort($operations, SORT_STRING);

            $out[] = new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-unrecoverable',
                message: sprintf(
                    '%s is a rule this build cannot read, so it is left off each of the %d fields it validates, across %d operations (%s); the rest of their rules are documented.',
                    (string) $first->source?->symbol,
                    count($members),
                    count($operations),
                    implode(', ', $operations),
                ),
                source: $first->source,
                help: $first->help,
            );
        }

        return array_values($out);
    }
}
