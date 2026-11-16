<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use ReflectionMethod;

/**
 * A validator's message bag as the map it is sent as: each field to the list of its messages, the `errors`
 * Laravel's own validation error renders. Claimed only where the bag is sent by the framework's own methods,
 * so a subclass that sends something else is left to the class mapper. `EARLY` so that mapper never reflects
 * one into a component of its internals.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class MessageBagTypeToSchema implements TypeToSchema
{
    private const MESSAGE_BAG = 'Illuminate\\Support\\MessageBag';

    /** What a bag is sent by, each calling the next. A class overriding any of them sends what the override returns. */
    private const SENT_BY = ['jsonSerialize', 'toArray', 'getMessages'];

    /** @var array<string, mixed> */
    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
    ];

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && ($type->fqcn === self::MESSAGE_BAG || is_subclass_of($type->fqcn, self::MESSAGE_BAG));
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT || ! $this->supports($type) || ! self::sentByTheFramework($type->fqcn)) {
            return null;
        }

        return new SchemaResult(self::SCHEMA);
    }

    private static function sentByTheFramework(string $fqcn): bool
    {
        foreach (self::SENT_BY as $method) {
            if ((new ReflectionMethod($fqcn, $method))->getDeclaringClass()->getName() !== self::MESSAGE_BAG) {
                return false;
            }
        }

        return true;
    }
}
