<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Documents a request whose action type-hints a `spatie/laravel-data` Data object. The class is found by
 * reflecting the action parameters — never constructed — recovered into a rule set by
 * {@see DataValidationRules}, and run through the shared validation chain, so this and the FormRequest
 * path converge on one representation.
 */
final class DataRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly DataValidationRules $rules = new DataValidationRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        private readonly RuleSetNormalizer $normalizer = new RuleSetNormalizer,
        private readonly RecoveredRequest $request = new RecoveredRequest,
        private readonly RulesFromClass $rulesOverride = new RulesFromClass,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $data = $this->dataParameter($context);
        if ($data === null) {
            return;
        }

        [$fqcn, $files] = $data;
        $context->recordDependencyFiles($files);

        $this->reportUnrecognisedMappers($fqcn, $context);

        $metadata = $context->engine->classMetadata(new ClassRef($fqcn));

        // A static rules() override wins per field over property inference. The already-documented field
        // keys go in so a dynamic rules() for one of them doesn't raise a stale rule-unrecoverable.
        $documentedByProperties = $this->rules->propertyFieldKeys($fqcn, $metadata, $context->engine);
        $overrides = $this->rulesOverride->analyse($context, $fqcn, $documentedByProperties);

        $converter = $context->converter();
        $validation = $context->validation();
        $ruleSet = $this->normalizer->normalize($this->rules->build($fqcn, $metadata, $context->engine, $overrides, $converter, $validation));
        $context->recordDependencyFiles($this->rules->dependencyFiles());
        if ($ruleSet->isEmpty()) {
            return;
        }

        RuleSetNormalizer::report($ruleSet, $context, $fqcn);

        $result = $validation->convert($this->ordering->order($ruleSet), $converter);
        if ($result->isEmpty()) {
            return;
        }

        $this->request->apply($operation, $context, $result, 'spatie-data', $fqcn, $this->inputKeys($fqcn, $metadata));
    }

    /**
     * PHP property name → the key the request accepts it under, for the declarations a property carries
     * ({@see RecoveredRequest::apply()}). `#[MapInputName]`/`#[MapName]` renames a field on the way IN,
     * and an input name is not the output name the response side maps by — a class mapped
     * `snake_case` in and `camelCase` out has two different maps, so each side asks for its own.
     *
     * @return array<string, string>
     */
    private function inputKeys(string $fqcn, ClassMetadata $metadata): array
    {
        $keys = [];
        foreach ($metadata->properties as $property) {
            $keys[$property->name] = $this->rules->reflector()->inputName($fqcn, $property->name);
        }

        return $keys;
    }

    /**
     * The first Data-typed action parameter as `[fqcn, declaration files]`, or null when the action takes
     * none. The whole hierarchy's files, not just the class's own: `#[MergeValidationRules]` and the
     * property attributes below it are inheritance-answered, so an edit to a base class changes this
     * request body ({@see DeclarationFiles}).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function dataParameter(RouteContext $context): ?array
    {
        $class = $context->actionRef->class;
        if ($class === null) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $context->actionRef->method);
        } catch (Throwable) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (DataClassReflector::isData($name)) {
                return [$name, DeclarationFiles::of($name)];
            }
        }

        return null;
    }

    private function reportUnrecognisedMappers(string $fqcn, RouteContext $context): void
    {
        foreach ($this->rules->reflector()->unrecognisedMappers($fqcn) as $mapper) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'spatie-data.unknown-mapper',
                message: sprintf('Data class %s uses an unrecognised name mapper %s; its property names are documented unmapped.', $fqcn, $mapper),
                routeSignature: $context->route->signature(),
            ));
        }
    }
}
