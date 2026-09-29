<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Support\HeaderNames;

/**
 * Publishes a FormRequest's rules for a key it copies from a header or a query value
 * ({@see CopiedInputs}) on that header or query parameter, the part of the request they actually validate.
 * Requiredness is {@see NullRejection}'s: the copied key is always present, null when the part was not sent.
 *
 * A header is only ever enriched, never minted: it runs after the request-header reads have published
 * every header the code may publish, so one they withheld — a transport header, an apiKey scheme's, the
 * API version header — stays withheld, and one declared under another case is found under that case. Its
 * body field still left: `merge()` overwrote it, so publishing it would tell a client to send a field the
 * server never reads. The rules outrank a bare read at the same layer and every declaration outranks them.
 */
#[ExtensionOrder(priority: Priorities::LAST - 1)]
final class CopiedInputParameters implements OperationExtension
{
    /** Presence is settled by {@see NullRejection}, so these say nothing about the parameter's schema. */
    private const PRESENCE = ['filled', 'nullable', 'present', 'required', 'sometimes'];

    public function __construct(
        private readonly RuleOrdering $ordering = new RuleOrdering,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // Exactly what the body gave up: a rule reaches a parameter here only by having left the body.
        foreach (CopiedInputs::movedFrom($operation) as $key => $moved) {
            $parameter = match ($moved['in']) {
                CopiedInputs::HEADER => self::publishedHeader($operation, $moved['name']),
                CopiedInputs::QUERY => $operation->parameter('query', $moved['name']),
                // The route already states its own parameter; the copy only had to leave the body.
                default => null,
            };

            if ($parameter !== null) {
                $this->publish($parameter, $context, $key, $moved['rules']);
            }
        }
    }

    /**
     * @param  list<ValidationRule>  $rules
     */
    private function publish(ParameterDraft $parameter, RouteContext $context, string $key, array $rules): void
    {
        $contribution = Contribution::integration('form-request', $context->actionSource(), specificity: 1);

        $parameter->setRequired(NullRejection::rejects($rules), $contribution);

        $shape = array_values(array_filter($rules, static fn (ValidationRule $rule): bool => ! in_array($rule->name, self::PRESENCE, true)));
        $schema = [];
        if ($shape !== []) {
            $result = $context->validation()->convert($this->ordering->order(new RuleSet([$key => $shape])), $context->converter());
            foreach ($result->diagnostics as $diagnostic) {
                $context->components->addDiagnostic($diagnostic);
            }

            $properties = $result->schema['properties'] ?? null;
            $field = is_array($properties) ? ($properties[$key] ?? null) : null;
            $schema = is_array($field) ? $field : [];
        }

        // A hint is an x-docuccino member, not a schema keyword, so it travels on the draft.
        $docuccino = $schema['x-docuccino'] ?? null;
        unset($schema['x-docuccino']);
        if (is_array($docuccino) && is_array($docuccino['mock'] ?? null)) {
            /** @var array<string, mixed> $mock */
            $mock = $docuccino['mock'];
            $parameter->schema()->assignMock($mock);
        }

        $description = $schema['description'] ?? null;
        unset($schema['description']);
        if (is_string($description)) {
            $parameter->setDescription($description, $contribution);
        }

        // Every value a header or query string carries is a string on the wire.
        $schema['type'] ??= 'string';

        foreach ($schema as $keyword => $value) {
            $parameter->schema()->set((string) $keyword, $value, $contribution);
        }
    }

    /** The header parameter already published under this header's name, in whatever case it was written. */
    private static function publishedHeader(OperationDraft $operation, string $name): ?ParameterDraft
    {
        $lookup = HeaderNames::lookupKey($name);

        foreach ($operation->parameterKeys() as $key) {
            if (str_starts_with($key, 'header:') && HeaderNames::lookupKey(substr($key, strlen('header:'))) === $lookup) {
                return $operation->parameter('header', substr($key, strlen('header:')));
            }
        }

        return null;
    }
}
