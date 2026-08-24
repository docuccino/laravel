<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;

/**
 * Hoists a page-of-X envelope's non-item members — `links`, `meta` — to components the envelope
 * `$ref`s, so the eleven-odd properties they carry are stated once per SHAPE rather than once per
 * paginated item type. {@see PageComponent} hoists the envelope; this hoists what is inside it, and
 * every producer that builds one goes through both.
 *
 * A page stays a FLAT object whose sub-objects are `$ref`s, never an `allOf` of a shared base and an
 * item-typed remainder. OpenAPI has no generics, so `data` is restated per item type whatever happens,
 * and `allOf` buys nothing for the members that aren't: generators handle it unevenly — some flatten
 * it, some mint an inheritance hierarchy — and it makes the one genuinely per-type member harder to
 * express, not easier.
 *
 * Which component a shape lands on is a function of the SHAPE, never of the kind that reached it first.
 * Each producer declares the name beside the shape it builds ({@see PaginationEnvelope::parts()},
 * {@see SpatieDataEnvelope::parts()}), so two kinds that build one shape name it once — Laravel's
 * length-aware and cursor pages share their `links` object — and two that differ never collide.
 *
 * A member is replaced only when it IS the shape its part names. One that isn't — a wrap key that took
 * the member's place, a shape some operation varied — keeps what it had, because a shared component
 * over it would publish a body that operation never yields.
 *
 * @phpstan-type Part array{name: string, schema: array<string, mixed>, list: bool}
 */
final class PaginationParts
{
    /**
     * One envelope member: the component name its shape publishes under, the shape itself, and whether
     * the member is a LIST of that shape rather than the shape.
     *
     * @param  array<string, mixed>  $schema
     * @return Part
     */
    public static function part(string $name, array $schema, bool $list = false): array
    {
        return ['name' => $name, 'schema' => $schema, 'list' => $list];
    }

    /**
     * The member as an envelope states it inline — the shape, or an array of it. The one definition of
     * a member's inline form: the policy-off document is exactly this, and the hoist recognises a member
     * by comparing against it.
     *
     * @param  Part  $part
     * @return array<string, mixed>
     */
    public static function inline(array $part): array
    {
        return $part['list'] ? ['type' => 'array', 'items' => $part['schema']] : $part['schema'];
    }

    /**
     * `$envelope` with every member that matches its declared part replaced by a `$ref` to that part's
     * component. A list member's ITEMS carry the `$ref`, exactly as `data`'s do.
     *
     * Hoisting is governed by the same `representation.pagination.components` policy that governs the
     * envelope: off, the envelope is restated in full on every operation, members and all.
     *
     * @param  array<string, mixed>  $envelope
     * @param  array<string, Part>  $parts
     * @return array<string, mixed>
     */
    public static function hoist(SchemaContext $context, array $envelope, array $parts): array
    {
        if (! $context->representation()->paginationComponents) {
            return $envelope;
        }

        $properties = $envelope['properties'] ?? null;
        if (! is_array($properties)) {
            return $envelope;
        }

        foreach ($parts as $member => $part) {
            $current = $properties[$member] ?? null;
            if (! is_array($current) || $current !== self::inline($part)) {
                continue;
            }

            $reference = $context->reference($part['name'], $part['schema']);
            $properties[$member] = $part['list'] ? ['type' => 'array', 'items' => $reference] : $reference;
        }

        $envelope['properties'] = $properties;

        return $envelope;
    }
}
