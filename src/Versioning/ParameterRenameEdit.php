<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\ChangedFieldExamples;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Support\ParameterLocations;

/**
 * `#[RenamedParameter]` as the transformer applies it: the parameter goes back to the name older
 * versions accept, and its identity is re-minted to match.
 *
 * **The re-mint is the load-bearing half.** A parameter's `x-docuccino.id` is a function of the
 * operation, the location AND the name, so a renamed parameter left carrying its old id publishes a
 * node whose identity claims a name it does not have — and everything downstream that resolves by id
 * (the contract index, per-node provenance, the differ's pairing) then answers about the wrong
 * parameter. Renaming without re-minting satisfies determinism and still lies.
 *
 * **No example moves.** This was worth checking rather than assuming: a parameter's `example` and its
 * `examples` map hold the parameter's own VALUE, and the entries of that map are keyed by example name.
 * The parameter's name appears nowhere inside them — not even for a `deepObject` container, whose
 * example is keyed by its MEMBERS — so unlike a schema-field rename there is no key to rewrite, and
 * {@see ChangedFieldExamples} has nothing to do here. The one position that
 * would carry a parameter name is an OAS Link Object's `parameters` map, which nothing this product
 * mints publishes; an overlay that writes one keeps what it wrote, the same way an `externalValue`
 * does.
 *
 * A parameter written as a `$ref` is left where it stands. It is shared with every site referencing it,
 * so renaming it in place would rename it for all of them — including operations a scope was written to
 * exclude — and this verb has no fork to offer, because a parameter is not something two operations
 * legitimately share in a document this product builds.
 *
 * @internal
 */
final readonly class ParameterRenameEdit implements OperationVerb
{
    /**
     * @param  string  $in  the location, already read against {@see ParameterLocations} and never
     *                      `path` — {@see VerbOrder} refuses that one, because a path parameter's name
     *                      is stated on the parameter AND as the path's own `{expression}`, and only
     *                      the first of the two is something a change can address
     * @param  string  $from  the name versions before the change accept
     * @param  string  $to  the name in the code today
     */
    public function __construct(
        private string $in,
        private string $from,
        private string $to,
    ) {}

    public function declares(): string
    {
        return sprintf('the %s parameter "%s"', $this->in, $this->to);
    }

    public function apply(array $operation, string $scope, IdentityGenerator $identity, VerbOutcome &$outcome): array
    {
        $parameters = $operation['parameters'] ?? null;
        if (! is_array($parameters)) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $operation;
        }

        $at = null;
        $taken = false;

        foreach ($parameters as $index => $parameter) {
            $name = is_array($parameter) ? $parameter['name'] ?? null : null;
            $in = is_array($parameter) ? $parameter['in'] ?? null : null;

            if (! is_string($name) || ! is_string($in) || strtolower($in) !== $this->in) {
                continue;
            }

            if ($name === $this->to) {
                $at = $index;
            }

            if ($name === $this->from) {
                $taken = true;
            }
        }

        if ($at === null) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $operation;
        }

        if ($taken) {
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $operation;
        }

        /** @var array<array-key, mixed> $parameter */
        $parameter = $parameters[$at];

        // Assigned rather than rebuilt, so the member keeps its position and everything else it carries.
        $parameter['name'] = $this->from;

        // Re-minted from the DOCUMENT's own `in`, never from the author's word for it. The id standing
        // here was minted from this spelling, and the match above folds case — so a parameter published
        // as `Query` would be re-minted under `query` and carry an id neither its old mint nor a fresh
        // mint of its new name produces, which is a node the differ pairs with nothing.
        $docuccino = $parameter['x-docuccino'] ?? null;
        $published = $parameter['in'] ?? null;
        if (is_array($docuccino) && is_string($docuccino['id'] ?? null) && is_string($published)) {
            $docuccino['id'] = $identity->parameterId($scope, $published, $this->from);
            $parameter['x-docuccino'] = $docuccino;
        }

        $parameters[$at] = $parameter;
        $operation['parameters'] = $parameters;

        $outcome = VerbOutcome::Applied;

        return $operation;
    }

    public function refused(string $operation, VersionChange $change): Diagnostic
    {
        return VersionChangeCollector::unapplicable($change->class, sprintf(
            'the operation "%s" already declares a %s parameter called "%s", so renaming "%s" onto it would collapse two parameters into one',
            $operation,
            $this->in,
            $this->from,
            $this->to,
        ));
    }

    /**
     * Nothing in scope declares it — which is one sentence rather than two, and that is a fact about
     * parameters rather than a shortcut. A schema verb can tell "the document publishes no such schema"
     * from "it does, and the field is gone"; a parameter has no node of its own to be published or not,
     * so there is exactly one thing to say.
     */
    public function unreached(VersionChange $change): Diagnostic
    {
        return VerbDiagnostics::targetMissing($change, sprintf(
            'renames %s, which %s declares',
            $this->declares(),
            $change->selectors === []
                ? 'no operation this document publishes'
                : 'no operation its #[AppliesTo] names',
        ), 'parameter');
    }
}
