<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * The RFC 9457 (`application/problem+json`) shapes the Problem Details preset hoists: the shared
 * `ProblemDetails` object every problem response builds on, plus the per-status reusable response
 * bodies with their examples. Pure data (no I/O) so a dataset test can drive every entry.
 */
final class ProblemDetailsSchema
{
    public const MEDIA_TYPE = 'application/problem+json';

    /** The identity the shared component dedupes under, regardless of how many responses reference it. */
    public const SCHEMA_ID = 'docuccino:problem-details';

    public const SCHEMA_NAME = 'ProblemDetails';

    /**
     * The RFC 9457 members (`type`, `title`, `status`, `detail`, `instance`). An app may extend a
     * problem with its own members, so the object stays open (no `additionalProperties: false`).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri', 'default' => 'about:blank'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'instance' => ['type' => 'string', 'format' => 'uri'],
            ],
        ];
    }

    /**
     * The per-status response component table (design coverage standard: a dataset test drives EVERY
     * entry). Each maps a framework exception FQCN to the reusable `#/components/responses/Problem*`
     * it hoists: its component name, HTTP status, human title, and an RFC 9457 example. `422`
     * additionally grafts the field-keyed `errors` map onto the shared schema.
     *
     * Status, validation-flag and title (the RFC reason phrase) come from the shared
     * {@see FrameworkExceptionTable} so this preset can never drift from the framework-errors tier;
     * only the RFC 9457 presentation (component name + human detail) is local.
     *
     * @return array<string, array{component: string, status: string, title: string, description: string, validation: bool}>
     */
    public static function table(): array
    {
        $presentation = [
            'Illuminate\\Validation\\ValidationException' => ['component' => 'ProblemValidation', 'description' => 'Validation failed'],
            'Illuminate\\Auth\\AuthenticationException' => ['component' => 'ProblemUnauthenticated', 'description' => 'Authentication is required'],
            'Illuminate\\Auth\\Access\\AuthorizationException' => ['component' => 'ProblemForbidden', 'description' => 'This action is unauthorized'],
            'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => ['component' => 'ProblemNotFound', 'description' => 'The resource was not found'],
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => ['component' => 'ProblemNotFound', 'description' => 'The resource was not found'],
        ];

        $table = [];
        foreach ($presentation as $fqcn => $local) {
            $facts = FrameworkExceptionTable::match($fqcn);
            if ($facts === null) {
                continue;
            }
            $table[$fqcn] = [
                'component' => $local['component'],
                'status' => $facts['status'],
                'title' => FrameworkExceptionTable::reason($facts['status']),
                'description' => $local['description'],
                'validation' => $facts['validation'],
            ];
        }

        return $table;
    }

    /**
     * The reusable response body for a table entry: the shared `ProblemDetails` (via `$ref`), the
     * problem media type, a per-status example, and — for 422 — the grafted `errors` map.
     *
     * @param  array{component: string, status: string, title: string, description: string, validation: bool}  $entry
     * @param  array<string, mixed>  $problemRef  the `{"$ref": …}` to the shared ProblemDetails schema
     * @return array<string, mixed>
     */
    public static function response(array $entry, array $problemRef, string $errorsShape = 'map'): array
    {
        $schema = $problemRef;
        $example = self::example($entry);

        if ($entry['validation']) {
            $errors = $errorsShape === 'pointer-list' ? self::pointerListErrors() : self::mapErrors();
            $schema = [
                'allOf' => [
                    $problemRef,
                    ['type' => 'object', 'properties' => ['errors' => $errors['schema']]],
                ],
            ];
            $example['errors'] = $errors['example'];
        }

        return [
            'description' => $entry['description'],
            'content' => [
                self::MEDIA_TYPE => [
                    'schema' => $schema,
                    'example' => $example,
                ],
            ],
        ];
    }

    /**
     * The inline problem response for an HttpException whose status is only known at document time
     * (dynamic status hint) — no shared component, just the ProblemDetails body under that status.
     *
     * @param  array<string, mixed>  $problemRef
     * @return array<string, mixed>
     */
    public static function dynamicResponse(int $status, array $problemRef): array
    {
        return [
            'description' => 'Error',
            'content' => [
                self::MEDIA_TYPE => [
                    'schema' => $problemRef,
                    'example' => ['type' => 'about:blank', 'title' => 'Error', 'status' => $status],
                ],
            ],
        ];
    }

    /**
     * The default 422 `errors` representation: a field-keyed map of message lists (`{field: [msg]}`),
     * matching Laravel's stock validation JSON.
     *
     * @return array{schema: array<string, mixed>, example: array<string, mixed>}
     */
    private static function mapErrors(): array
    {
        return [
            'schema' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'example' => ['field' => ['The field is invalid.']],
        ];
    }

    /**
     * The `pointer-list` 422 `errors` representation: a list of `{detail, pointer}` objects, where
     * `pointer` is a JSON Pointer to the offending member (RFC 9457 style).
     *
     * @return array{schema: array<string, mixed>, example: list<array<string, string>>}
     */
    private static function pointerListErrors(): array
    {
        return [
            'schema' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'detail' => ['type' => 'string'],
                        'pointer' => ['type' => 'string'],
                    ],
                    'required' => ['detail', 'pointer'],
                ],
            ],
            'example' => [['detail' => 'The field is invalid.', 'pointer' => '#/field']],
        ];
    }

    /**
     * @param  array{component: string, status: string, title: string, description: string, validation: bool}  $entry
     * @return array<string, mixed>
     */
    private static function example(array $entry): array
    {
        return [
            'type' => 'about:blank',
            'title' => $entry['title'],
            'status' => (int) $entry['status'],
            'detail' => $entry['description'].'.',
        ];
    }
}
