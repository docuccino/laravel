<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Remove;

/**
 * Holds a page size's literal fallback ({@see PaginatorPageParameter::size()}) to the bounds its parameter
 * finally publishes. The size producers write their `default` in the parameters phase, and the request's
 * validation rules merge their bounds onto the same key a phase later, so `integer('per_page', 50)` under
 * `max:20` would publish a default its own schema rejects. The server still serves the fallback when the
 * key is absent, so the value is never clamped: it leaves the schema and is stated beneath the
 * description instead.
 *
 * The producers record each fallback here ({@see record()}), since this pass runs at `Finalize`, once
 * every producer of a bound has written it.
 */
final class PageSizeDefaultExtension implements OperationExtension
{
    private const string CHANNEL = 'page-size.default';

    /** Outranks the producers' own integration-layer write of the default, and nothing above that layer. */
    private const int SPECIFICITY = 1;

    public static function record(RouteContext $context, RequestPageSizeKey $size): void
    {
        if ($size->default !== null) {
            $context->notes()->record(self::CHANNEL, $size->key, (string) $size->default);
        }
    }

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->notes()->all()[self::CHANNEL] ?? [] as $key => $defaults) {
            if (count($defaults) !== 1 || ! $operation->hasParameter('query', (string) $key)) {
                continue;
            }

            $default = (int) $defaults[0];
            $parameter = $operation->parameter('query', (string) $key);
            $schema = $parameter->schema();

            // A default some higher layer wrote is that author's to keep, so only the producers' own is read.
            if ($schema->resolvedField('default') !== $default || self::allows($schema, $default)) {
                continue;
            }

            $by = Contribution::integration('page-size', $context->actionSource(), specificity: self::SPECIFICITY);
            $schema->set('default', Remove::value(), $by);
            if ($schema->producerFor('default') === $by->producer) {
                $parameter->declareDescriptionNote(sprintf('Defaults to `%d`.', $default), $by);
            }
        }
    }

    /** Whether the bounds and value list the schema finally carries admit the default. */
    private static function allows(SchemaDraft $schema, int $default): bool
    {
        $minimum = $schema->resolvedField('minimum');
        $maximum = $schema->resolvedField('maximum');
        $exclusiveMinimum = $schema->resolvedField('exclusiveMinimum');
        $exclusiveMaximum = $schema->resolvedField('exclusiveMaximum');
        $enum = $schema->resolvedField('enum');

        return ! (is_numeric($minimum) && $default < $minimum)
            && ! (is_numeric($maximum) && $default > $maximum)
            && ! (is_numeric($exclusiveMinimum) && $default <= $exclusiveMinimum)
            && ! (is_numeric($exclusiveMaximum) && $default >= $exclusiveMaximum)
            && ! (is_array($enum) && ! in_array($default, $enum, true));
    }
}
