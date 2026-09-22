<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Workflows;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Support\Json;
use Throwable;

/**
 * The workflow steps one route declared, on the {@see RouteContext::notes()} channel the assembly
 * drains.
 *
 * A notes channel because the two halves are separate passes, and — the load-bearing half — because a
 * note rides the OPERATION FRAGMENT. A step recorded straight into the assembly would be lost on a warm
 * cache hit, and a workflow that quietly loses a step on the second build is worse than one that never
 * assembled: the document would publish a shorter sequence and say nothing about it.
 *
 * The value is the step as JSON. A note carries strings, and a step is structured — the alternative,
 * one channel per member, would put the members of one step back together by position across several
 * lists, which is the shape that goes wrong the first time a member is absent.
 *
 * @internal
 */
final class DeclaredSteps
{
    public const string CHANNEL = 'workflow.step';

    /** The member that tells a step apart from the note left where one could not be recorded. */
    public const string UNREADABLE = 'unreadable';

    /**
     * @param  array<string, mixed>  $step
     */
    public static function record(RouteContext $context, string $workflow, array $step): void
    {
        $context->notes()->record(self::CHANNEL, $workflow, self::encode($step));
    }

    /**
     * The step as JSON, or the note that says one was declared and could not be carried.
     *
     * **`Throwable`, not `JsonException`.** `json_encode` refuses more than malformed UTF-8: a pure
     * enum case in a `body` or a `parameters` value — ordinary PHP to write in an attribute — raises
     * its own error type, and an uncaught one out of an extension aborts the whole build naming
     * neither the route nor the attribute. Degrading is the intent; the catch has to be as wide as the
     * things that can happen.
     *
     * **And the failure is RECORDED rather than dropped.** A step that silently vanished would leave
     * the document publishing a shorter sequence with nothing said about it — a workflow claiming
     * three calls get you there while omitting one, which is the degradation that is not true. The
     * marker rides the same channel so it reaches the assembly the same way a step does, and the
     * assembly reports it.
     *
     * `serialize_precision` is pinned for the encode for the reason {@see Json::stable()} gives: these
     * values reach the PUBLISHED artifact, and a float encoded at whatever the build host is
     * configured with is the machine deciding what the document says. `JSON_PRESERVE_ZERO_FRACTION`
     * keeps `1.0` a float rather than letting it come back an int, which a YAML export would show.
     *
     * @param  array<string, mixed>  $step
     */
    private static function encode(array $step): string
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            return (string) json_encode($step, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable $failure) {
            return (string) json_encode([
                self::UNREADABLE => $failure->getMessage(),
                'route' => is_string($step['route'] ?? null) ? $step['route'] : '',
            ]);
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    /**
     * The steps `$values` carry, decoded. Anything that does not decode to a map is dropped: the only
     * writer is {@see record()}, so a value that is not one came from a cache somebody edited.
     *
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    public static function decode(array $values): array
    {
        $steps = [];

        foreach ($values as $value) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $steps[] = $decoded;
            }
        }

        return $steps;
    }
}
