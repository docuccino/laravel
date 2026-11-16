<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\MessageBagTypeToSchema;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Support\MessageBag;
use Opis\JsonSchema\Validator;

/**
 * A validator's message bag put in a response body — `$validator->errors()` under a key of the application's
 * own envelope — checked against what the framework sends for it: `json_encode` of each field's list of
 * messages, which is the same `errors` map Laravel's own validation error renders.
 */
beforeEach(function (): void {
    $this->published = static function (string $fqcn): array {
        $converter = new SchemaConverter([new MessageBagTypeToSchema, ...DefaultTypeMappers::all()], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
        $schema = $converter->toSchema(new ClassT($fqcn))->schema;
        unset($schema['x-docuccino']);

        return $schema;
    };
});

it('publishes the map of messages a failed validation sends, which the body really is', function (): void {
    $validator = ValidatorFactory::make(['title' => '', 'tags' => 'x'], ['title' => 'required', 'tags' => 'array|min:2']);
    expect($validator->fails())->toBeTrue();

    $sent = json_decode((string) response()->json(['errors' => $validator->errors()])->getContent());
    assert($sent instanceof stdClass);

    $schema = json_decode((string) json_encode(($this->published)(MessageBag::class)));
    assert($schema instanceof stdClass);

    expect((new Validator)->validate($sent->errors, $schema)->isValid())->toBeTrue()
        // …and refuses what a bag never sends, so the accepting run above proves something.
        ->and((new Validator)->validate((object) ['title' => 'required'], $schema)->isValid())->toBeFalse();
});

it('publishes the same errors map as the framework’s own validation error, so one shape is one schema', function (): void {
    $framework = FrameworkErrorsExceptionToResponse::table()['Illuminate\\Validation\\ValidationException']['shape']['properties']['errors'];

    expect(($this->published)(MessageBag::class))->toBe($framework)
        ->and(MessageBagTypeToSchema::SCHEMA)->toBe($framework);
});

it('leaves a bag sent by a method of its own to the class mapper', function (string $method): void {
    $bag = match ($method) {
        'jsonSerialize' => new class extends MessageBag
        {
            public function jsonSerialize(): array
            {
                return ['count' => count($this->messages)];
            }
        },
        'toArray' => new class extends MessageBag
        {
            public function toArray(): array
            {
                return ['count' => count($this->messages)];
            }
        },
        'getMessages' => new class extends MessageBag
        {
            public function getMessages(): array
            {
                return [];
            }
        },
    };

    expect(($this->published)($bag::class))->not->toBe(MessageBagTypeToSchema::SCHEMA);
})->with(['jsonSerialize', 'toArray', 'getMessages']);

it('claims a subclass that sends what the framework’s bag sends', function (): void {
    $bag = new class extends MessageBag
    {
        public function headline(): string
        {
            return (string) $this->first();
        }
    };

    expect(($this->published)($bag::class))->toBe(MessageBagTypeToSchema::SCHEMA);
});
