<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FrameworkErrors;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Tier 2 of the error-response chain (design §6): Laravel's stock JSON error shapes, keyed by the
 * exception the framework renders to each status. `401`/`403`/`404` are `{message}`; `422` adds the
 * field-keyed `errors` map.
 *
 * Ordered LATE — after the inferred-handler tier (FIRST) and any active preset (EARLY), before the
 * terminal fallback (LAST) — so a real handler or preset always wins and this only covers what neither
 * did. Matching is subtype-aware; an exception outside the table is declined so the chain continues.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class FrameworkErrorsExceptionToResponse implements ExceptionToResponse
{
    /**
     * Base exception FQCN → status, reason phrase and body shape. Status and phrase come from the shared
     * {@see FrameworkExceptionTable}; only the JSON shapes are this tier's own.
     *
     * @return array<string, array{status: string, description: string, shape: array<string, mixed>}>
     */
    public static function table(): array
    {
        $table = [];
        foreach (FrameworkExceptionTable::exceptions() as $fqcn) {
            $facts = FrameworkExceptionTable::match($fqcn);
            if ($facts === null) {
                continue;
            }
            $table[$fqcn] = [
                'status' => $facts['status'],
                'description' => FrameworkExceptionTable::reason($facts['status']),
                'shape' => $facts['validation'] ? self::validationShape() : self::messageShape(),
            ];
        }

        return $table;
    }

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return $this->match($exception->exceptionFqcn) !== null;
    }

    public function producer(): string
    {
        return 'integration:framework-errors';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft {
        $entry = $this->match($exception->exceptionFqcn);
        if ($entry === null) {
            return null;
        }

        $contribution = Contribution::integration('framework-errors');
        $draft = new ResponseDraft($entry['status']);
        $draft->setDescription($entry['description'], $contribution);
        // This tier speaks for one kind of error per status, so it can name the shared component after
        // the error rather than after the number.
        $draft->claimComponentName(FrameworkExceptionTable::componentName($entry['status']), $contribution);

        foreach ($entry['shape'] as $keyword => $value) {
            $draft->content('application/json')->set($keyword, $value, $contribution);
        }

        return $draft;
    }

    /**
     * Subtype-aware lookup, so a subclass inherits its mapped base's shape.
     *
     * @return array{status: string, description: string, shape: array<string, mixed>}|null
     */
    private function match(string $fqcn): ?array
    {
        foreach (self::table() as $base => $entry) {
            if ($fqcn === $base || is_a($fqcn, $base, true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function messageShape(): array
    {
        return [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ];
    }

    /**
     * `{message}` plus the field-keyed `errors` map Laravel renders from the validator.
     *
     * @return array<string, mixed>
     */
    private static function validationShape(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'required' => ['message', 'errors'],
        ];
    }
}
