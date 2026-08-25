<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Patch\Layer;

/**
 * What to do about a field, given the rung that won it. Someone runs `docuccino:explain` because an
 * endpoint is documented wrongly, and the remedy is derivable: the ladder says which rungs outrank the
 * winner, and for many fields one specific attribute is the lever at the attribute rung.
 *
 * This is author guidance, which is why it lives on the CLI and never in the emitted document — the
 * document is read by API consumers, who cannot act on an attribute name.
 *
 * Honesty is the whole design here. A lever is named only where the attribute genuinely writes that
 * field on that node (`#[Group]` really is what sets `tags`); everywhere else the answer is the
 * generic truth — which rungs outrank the winner, and that an overlay can write any field. A wrong
 * lever is worse than none, because the reader tries it and it does nothing.
 *
 * @internal
 */
final class OverrideHint
{
    /**
     * The attribute that owns a parameter, by the `in` the label names.
     *
     * @var array<string, string>
     */
    private const array PARAMETER_ATTRIBUTES = [
        'query' => 'QueryParameter',
        'path' => 'PathParameter',
        'header' => 'HeaderParameter',
        'cookie' => 'CookieParameter',
    ];

    /**
     * The operation-level fields one attribute writes, field => attribute snippet.
     *
     * @var array<string, string>
     */
    private const array OPERATION_ATTRIBUTES = [
        'tags' => "#[Group('Invoices')]",
        'operationId' => "#[OperationId('storeInvoice')]",
        'deprecated' => '#[DeprecatedOperation]',
        'summary' => "#[Summary('Create an invoice')]",
        'description' => "#[Description('…')]",
        'requestBody' => "#[BodyParameter(name: 'total')]",
        'x-internal' => '#[Internal]',
    ];

    /** One line, or null where the winner leaves nothing useful to say. */
    public static function for(string $nodeLabel, string $field, Layer $winner): string
    {
        if ($winner === Layer::Config) {
            return 'config is the top rung — edit config/docuccino.php';
        }

        if ($winner === Layer::Overlay) {
            return 'edit the overlay that set it; only config outranks an overlay';
        }

        if ($winner === Layer::Attribute) {
            return 'edit the attribute above, or outrank it with an overlay';
        }

        $lever = self::lever($nodeLabel, $field);

        return $lever !== null
            ? 'set it with '.$lever
            : 'no attribute writes this — an overlay outranks '.$winner->label();
    }

    /**
     * The attribute that writes this field on this node, or null where none does. Null is the common
     * answer and the safe one: a schema keyword deep inside a response body, an `x-` member an
     * integration invented, anything an application's own extension wrote.
     */
    private static function lever(string $nodeLabel, string $field): ?string
    {
        if ($nodeLabel === 'operation') {
            return self::OPERATION_ATTRIBUTES[$field] ?? null;
        }

        $parameter = self::parameterLever($nodeLabel);
        if ($parameter !== null) {
            return $parameter;
        }

        return self::responseLever($nodeLabel, $field);
    }

    /**
     * `parameters.query:status` and everything under it — the schema node included, since the
     * attribute writes the parameter's schema keywords too.
     */
    private static function parameterLever(string $nodeLabel): ?string
    {
        if (! str_starts_with($nodeLabel, 'parameters.')) {
            return null;
        }

        $head = explode('.', substr($nodeLabel, strlen('parameters.')))[0];
        $parts = explode(':', $head, 2);

        $attribute = self::PARAMETER_ATTRIBUTES[$parts[0]] ?? null;

        return $attribute === null || ($parts[1] ?? '') === ''
            ? null
            : sprintf("#[%s(name: '%s')]", $attribute, $parts[1]);
    }

    /**
     * `responses.201` and everything under it. Headers have an attribute of their own; the body and
     * its description are the one `#[Response]` for that status.
     */
    private static function responseLever(string $nodeLabel, string $field): ?string
    {
        if (! str_starts_with($nodeLabel, 'responses.')) {
            return null;
        }

        $status = explode('.', substr($nodeLabel, strlen('responses.')))[0];
        if ($status === '' || ! ctype_alnum($status)) {
            return null;
        }

        return match (true) {
            // Not a member of the response at all — it is the name the shared error body is published
            // under. Two anchors write it, and the one nearest the operation is the one to name here.
            $field === 'component' => sprintf(
                "#[Response(status: %s, errorComponent: 'InvoiceNotFound')], or #[ErrorComponent] on the exception or its render method",
                $status,
            ),
            $field === 'headers' => sprintf("#[ResponseHeader(name: 'X-Total', status: %s)]", $status),
            $field === 'description' => sprintf("#[Response(status: %s, description: '…')]", $status),
            default => sprintf('#[Response(status: %s, type: Invoice::class)]', $status),
        };
    }
}
