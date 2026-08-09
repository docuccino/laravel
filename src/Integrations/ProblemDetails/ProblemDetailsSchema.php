<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * The RFC 9457 (`application/problem+json`) shapes the Problem Details preset hoists: the shared
 * `ProblemDetails` object, plus the per-status reusable response bodies and their examples. Pure data,
 * so a dataset test can drive every entry.
 */
final class ProblemDetailsSchema
{
    public const MEDIA_TYPE = 'application/problem+json';

    /** The identity the shared component dedupes under, however many responses reference it. */
    public const SCHEMA_ID = 'docuccino:problem-details';

    public const SCHEMA_NAME = 'ProblemDetails';

    /**
     * The RFC 9457 members. Stays open (no `additionalProperties: false`) — apps may add their own.
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
     * Framework exception FQCN → the reusable `#/components/responses/Problem*` it hoists. Status,
     * validation flag and title come from {@see FrameworkExceptionTable} so this preset can't drift from
     * the framework-errors tier; only the RFC 9457 presentation (component name, human detail) is local.
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
     * The reusable response body for a table entry. Validation entries graft an `errors` member onto the
     * shared schema via `allOf`.
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
     * For an HttpException whose status is only known at document time: inline, no shared component.
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
     * Default 422 `errors`: a field-keyed map of message lists, matching Laravel's stock validation JSON.
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
     * The `pointer-list` 422 `errors`: RFC 9457-style `{detail, pointer}` objects, `pointer` being a JSON
     * Pointer to the offending member.
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
