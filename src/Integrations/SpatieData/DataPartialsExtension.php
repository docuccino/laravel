<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkClasses;

/**
 * Documents the `spatie/laravel-data` request query-string partials — `include`, `exclude`, `only`,
 * `except` (docs: as-a-resource/lazy-properties, includeable data) — on an operation whose action
 * RETURNS a Data object (or Data collection) that opts into them by overriding the matching
 * `allowedRequest*()` static method ({@see DataClassReflector::requestPartials()}). Each becomes an
 * optional query parameter carrying a comma-separated list of property/relation paths; the allow-list
 * itself is not enumerated (that would run the method), so the parameter is a free string.
 *
 * The Data class is read from the action's analysed return types (JsonResponse-unwrapped), the same
 * return-driven trigger the JSON:API parameters use — so a `store(Data $d): Data` gets both a request
 * body (via {@see DataRequestExtension}) and these response-shaping query params.
 */
final class DataPartialsExtension implements OperationExtension
{
    /** @var array<string, string> */
    private const DESCRIPTIONS = [
        'include' => 'Comma-separated list of lazy/optional properties to include in the response.',
        'exclude' => 'Comma-separated list of properties to exclude from the response.',
        'only' => 'Comma-separated allow-list of the only properties to return.',
        'except' => 'Comma-separated deny-list of properties to omit from the response.',
    ];

    public function __construct(private readonly DataClassReflector $reflector = new DataClassReflector) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $fqcn = $this->dataReturn($context);
        if ($fqcn === null) {
            return;
        }

        $partials = $this->reflector->requestPartials($fqcn);
        if ($partials === []) {
            return;
        }

        $contribution = Contribution::integration('spatie-data', $context->actionSource());
        foreach ($partials as $partial) {
            $parameter = $operation->parameter('query', $partial);
            $parameter->setDescription(self::DESCRIPTIONS[$partial], $contribution);
            $parameter->setRequired(false, $contribution);
            $parameter->schema()->set('type', 'string', $contribution);
        }
    }

    /**
     * The Data class an action returns — a single Data object, or the item of a Data collection —
     * across its analysed return paths (JsonResponse-unwrapped), or null when it returns no Data.
     */
    private function dataReturn(RouteContext $context): ?string
    {
        foreach ($context->analysis()->returns as $return) {
            $type = self::unwrap($return->type);
            if (! $type instanceof ClassT) {
                continue;
            }

            if (DataClassReflector::isData($type->fqcn)) {
                return $type->fqcn;
            }

            if (DataClassReflector::isDataCollection($type->fqcn)) {
                $item = DataClassReflector::collectionValueType($type);
                if ($item instanceof ClassT && DataClassReflector::isData($item->fqcn)) {
                    return $item->fqcn;
                }
            }
        }

        return null;
    }

    /** Unwrap a `JsonResponse<payload>` to its payload type; other types pass through. */
    private static function unwrap(DType $type): DType
    {
        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            // JsonResponse is single-arg, so the payload is the last (only) type arg.
            $args = $type->typeArgs;

            return $args === [] ? $type : $args[array_key_last($args)];
        }

        return $type;
    }
}
