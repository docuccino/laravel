<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\BoundedNumber;
use Docuccino\Core\Support\FormatSamples;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use Docuccino\Laravel\Support\FrameworkClasses;
use stdClass;

/**
 * Builds an error {@see ResponseDraft} from a handler/closure analysis (design §6): reads the recovered
 * `JsonResponse<TPayload, TStatus, TContentType, TMembers>` for the real status, payload shape and content
 * type (default `application/json`, `application/problem+json` when the helper set that header), then hoists
 * the payload schema through the route's converter.
 *
 * The example carries only members that folded to a literal — a {@see StatusMarkerT} member among them,
 * resolved to this response's status, so the 403 arm says `403` — and required members that didn't fold
 * are filled with type-derived placeholders so the example is a valid instance of the schema beside it
 * ({@see example()} for why that fill is confined to examples and nothing else). A status that didn't fold
 * falls back to the one the body states, and only then to the exception's own hint ({@see foldStatus()}).
 *
 * When the body is an object the engine watched being constructed, the fourth type arg names the arguments
 * it was built with, and those ADD to the example's membership over the schema's `required` list rather
 * than replacing it ({@see example()} for the whole rule, {@see suppliedMembers()} for what the map says).
 * Only the schema is ever consulted for what such a member should look like.
 *
 * What this tier answers with where only half the response folded is design §6, "The inferred-handler
 * tier, and the four facts it answers for": the four facts worth an answer at all ({@see build()}), the
 * classification an unread status is filed under
 * ({@see FrameworkExceptionTable::classification()}), and why a body it could not read is a media type
 * under an EMPTY schema rather than no `content`.
 *
 * Null means this tier has no answer: no `JsonResponse` was recovered — either a `return null`/void arm
 * ({@see isDelegation()} — the renderer handing the type back to the framework, not a fold failure) or a
 * body too dynamic to fold — or one was recovered that says nothing worth publishing. Reason phrases come
 * from {@see FrameworkExceptionTable} so this tier can't drift from the others.
 */
final class HandlerResponseBuilder
{
    /** How far a placeholder follows nested schemas before flattening — a self-referential one never ends. */
    private const PLACEHOLDER_DEPTH = 4;

    /**
     * Where a bounded number starts from. ZERO, for the reason core's factory states: this fills a bare
     * `type: integer` member too, where the seed is the whole answer, and the neutral value claims least.
     */
    private const NUMBER_SEED = 0;

    /** What a `JsonResponse` is sent as when the render path stated no content type of its own. */
    private const DEFAULT_MEDIA_TYPE = 'application/json';

    public static function build(
        ActionAnalysis $analysis,
        RouteContext $context,
        Contribution $contribution,
        ThrownException $exception,
        string $renderer,
    ): ?ResponseDraft {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || $type->fqcn !== FrameworkClasses::JSON_RESPONSE) {
                continue;
            }

            $payload = $type->typeArgs[0] ?? null;
            $members = self::suppliedMembers($type->typeArgs[3] ?? null);
            $statusArg = $type->typeArgs[1] ?? null;
            $mediaType = self::statedContentType($type->typeArgs[2] ?? null);

            // Nothing anywhere stated a status. What the render path FOLDED is still proven — the shape,
            // or at least the media type it is sent as — and the only thing missing is the key to file it
            // under, so it is filed under the exception's classification rather than dropped onto a tier
            // that would assert a different media type over it. With neither there is nothing to keep,
            // which the one guard below answers for this branch too: a classification is never a status
            // HTTP forbids a body on, so the guard reduces to exactly "no body and no media type here".
            $status = self::foldStatus($statusArg, $payload, $members, $exception->httpStatusHint)
                ?? FrameworkExceptionTable::classification($exception->exceptionFqcn);

            $draft = new ResponseDraft($status);

            // Nothing recovered: no body, and a status the throw already carried. Answering anyway would
            // publish an error response with no `content` — which says the error returns NOTHING, a claim
            // and not a silence — and, being an answer, would stop the chain before a tier that can state a
            // body is asked ({@see ExceptionToResponse}: null defers). So the tier declines, exactly as its
            // own contract says it does for a body too dynamic to fold, and the deferral log turns it into
            // one `inferred-handler.too-dynamic` diagnostic naming the callback.
            //
            // A status HTTP forbids a body on is no failure — there, no content is the truth. Neither is a
            // status this tier FOLDED itself, nor a MEDIA TYPE it folded: each is a fact no later tier has
            // (they classify the exception type without reading the renderer), so the response keeps it and
            // states only what it knows.
            if (! self::statesBody($payload) && $mediaType === null && ! $draft->isBodyless() && ! self::statesStatus($statusArg, $payload, $members)) {
                return null;
            }

            $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);

            // The name the render path declared for THIS body. Claimed here, as a producer's own name, so
            // the ladder settles it without a rung of its own: a name on the exception class replaces the
            // status default and nothing a producer named itself
            // ({@see \Docuccino\Laravel\Exceptions\DeclaredErrorComponent::mayReplace()}), so the method
            // anchor outranks the class anchor by saying more, and a mapper ordered ahead of this tier
            // still never gets here. A name and a body always come off one arm, so two throws meeting at
            // one status cannot publish one arm's name over another's body.
            $draft->claimComponentName($return->component?->name, $contribution);

            if (! $draft->isBodyless() && self::statesBody($payload)) {
                $media = $mediaType ?? self::DEFAULT_MEDIA_TYPE;
                $payload = self::resolveStatusMarkers($payload, (int) $status);
                $schema = $context->converter()->toSchema($payload)->schema;
                foreach ($schema as $keyword => $value) {
                    $draft->content($media)->set($keyword, $value, $contribution);
                }
                [$example, $placeholders] = self::example($payload, $schema, (int) $status, $context, $members);
                if ($example !== [] && self::satisfies($example, self::resolveSchema($schema, $context))) {
                    $draft->setExample($media, $example, $placeholders);
                }
            } elseif (! $draft->isBodyless()) {
                // The widened answer: the representation is stated, the shape is not. Registering the
                // media type and writing no keyword into it is what publishes an empty schema — see the
                // class docblock for why that beats both `{type: object}` and no schema at all. Where the
                // render path folded no type of its own the response is a `JsonResponse`, which sends
                // `application/json` — the same reading the folded-body branch above makes, and the only
                // one that keeps a response the tier ANSWERS for from claiming the error has no body. The
                // author is told the shape was lost, since a partial recovery that says nothing is a
                // silent degradation.
                $draft->content($mediaType ?? self::DEFAULT_MEDIA_TYPE);
                HandlerDeferralLog::record($context, $renderer, $exception->exceptionFqcn);
            }

            return $draft;
        }

        return null;
    }

    /**
     * Every recovered return is a `return null`/void arm, i.e. the renderer delegates to the framework.
     * The tier defers silently on these rather than raising a too-dynamic deferral.
     */
    public static function isDelegation(ActionAnalysis $analysis): bool
    {
        if ($analysis->returns === []) {
            return false;
        }

        foreach ($analysis->returns as $return) {
            if (! $return->type instanceof VoidT && ! $return->type instanceof NullT) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the recovered payload says what the body IS. `null` is the refiner declining outright (the
     * shape never came back), {@see UnknownT} is it saying the body did not fold; a void/never payload is a
     * fold that reached no value. None of the four is something to document.
     *
     * @phpstan-assert-if-true !null $payload
     */
    private static function statesBody(?DType $payload): bool
    {
        return $payload !== null
            && ! $payload instanceof VoidT
            && ! $payload instanceof NeverT
            && ! $payload instanceof UnknownT;
    }

    /**
     * Whether the recovered response states a status of its OWN — one the render path folded, either as the
     * response's status or in the body beside it ({@see foldStatus()} reads them in that order) — rather
     * than borrowing the hint the throw arrived with, which every later tier has too.
     *
     * @param  array<string, DType>  $members
     */
    private static function statesStatus(mixed $statusArg, ?DType $payload, array $members): bool
    {
        return ($statusArg instanceof LiteralT && is_int($statusArg->value))
            || self::statedStatus($payload, $members) !== null;
    }

    /**
     * The status this response is READ to be, or null when nothing states one — neither side of the render
     * path folded, and the throw arrived without a status of its own. Only a reading comes back here; what
     * {@see build()} does with the null is where a classification may stand in for one.
     *
     * @param  array<string, DType>  $members
     */
    private static function foldStatus(mixed $statusArg, ?DType $payload, array $members, ?int $statusHint): ?string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        // Didn't fold (e.g. an enum method result, or a `$this->status` read a Data object's own
        // `toResponse()` makes). The body's own `status` may still have folded on the very same path, and
        // that beats the hint: the hint classifies the exception TYPE without reading the renderer at all,
        // so trusting it over folded evidence files the response under one status while the body beside it
        // states another — and names the shared component for the wrong one.
        $stated = self::statedStatus($payload, $members);
        if ($stated !== null) {
            return (string) $stated;
        }

        // Nothing folded either side — the status the throw arrived with is the last reading available,
        // and where it carried none nothing read one at all ({@see build()}).
        return $statusHint === null ? null : (string) $statusHint;
    }

    /**
     * The status the body itself states, when a `status` member folded to a real HTTP status. A
     * {@see StatusMarkerT} member is deliberately not one: it means "echoes the response status", so
     * reading the status back out of it would be circular.
     *
     * @param  array<string, DType>  $members
     */
    private static function statedStatus(?DType $payload, array $members): ?int
    {
        $member = $members['status'] ?? self::shapeField($payload, 'status');

        if (! $member instanceof LiteralT || ! is_int($member->value) || $member->value < 100 || $member->value > 599) {
            return null;
        }

        return $member->value;
    }

    /** One field of an array-shape payload, by key. */
    private static function shapeField(?DType $payload, string $key): ?DType
    {
        if (! $payload instanceof ArrayShapeT || $payload->isList) {
            return null;
        }

        foreach ($payload->fields as $field) {
            if ((string) $field->key === $key) {
                return $field->type;
            }
        }

        return null;
    }

    /**
     * The media type the render path FOLDED, or null when it did not — the helper's own default is not
     * one, since the whole point of asking is to tell a type this build read off the renderer from a type
     * every later tier would have assumed anyway ({@see build()}).
     */
    private static function statedContentType(mixed $contentTypeArg): ?string
    {
        return $contentTypeArg instanceof LiteralT && is_string($contentTypeArg->value)
            ? $contentTypeArg->value
            : null;
    }

    /**
     * Pin each top-level status-echo member to the status this response is documented under, so it
     * converts to a `const` integer and lands in the example. Non-shape payloads pass through.
     */
    private static function resolveStatusMarkers(DType $payload, int $status): DType
    {
        if (! $payload instanceof ArrayShapeT) {
            return $payload;
        }

        return $payload->mapFieldTypes(
            static fn (DType $type): DType => $type instanceof StatusMarkerT ? new LiteralT($status) : $type,
        );
    }

    /**
     * A complete example for the documented body, or `[]` when the schema gives nothing to build one from.
     *
     * Members that folded to a literal are used verbatim; every other member the response is known to carry
     * is filled from the schema so the result is a valid instance rather than a partial one that fails
     * validation against the very schema it sits beside. That fill is the one place this tier writes a value
     * the code didn't state, and it is confined to examples — an example is illustrative by definition,
     * whereas a schema is a claim. A member whose schema states a value of its own (an `@example`, a PHP
     * default) is filled with that; everything else gets an obvious placeholder derived from the declared
     * type, never a sentence invented on the app's behalf.
     *
     * "Known to carry" is `$members` first and the schema's `required` list second. A member the engine
     * watched being passed to the payload's constructor is in this response whatever its optionality says,
     * because being supplied at this call site is the stronger fact; a member the schema requires is in it
     * too, because the schema says so. Everything else is left out — the example shows the body this branch
     * produces, not every key that might appear.
     *
     * The single exception is `status`: a required, unconstrained integer member of that name is the status
     * this response is documented under (RFC 9457's own convention, and most of what makes a rendered
     * example worth reading), so it gets the real number.
     *
     * Which members were filled from the DECLARED TYPE alone travels back with the example, because
     * nothing downstream can work it out again: `"string"` filled for an unread member and `"string"`
     * returned by the code are the same bytes, and only the build that filled one knows which it is
     * ({@see ResponseDraft::setExample()} for where the answer goes and what reads it).
     *
     * @param  array<string, mixed>  $schema  the converted body schema, possibly a bare `$ref`
     * @param  array<string, DType>  $members  supplied constructor argument → its folded literal or {@see UnknownT}
     * @return array{array<string, mixed>, list<string>} the example, and the members nothing but their
     *                                                   declared type answered for, in name order
     */
    private static function example(DType $payload, array $schema, int $status, RouteContext $context, array $members): array
    {
        $folded = self::assembleExample($payload);
        $resolved = self::resolveSchema($schema, $context);

        $properties = $resolved['properties'] ?? null;
        if (! is_array($properties) || $properties === []) {
            return [$folded, []];
        }

        $required = is_array($resolved['required'] ?? null) ? $resolved['required'] : [];

        $example = [];
        $derived = [];
        foreach ($properties as $name => $spec) {
            $name = (string) $name;
            $spec = is_array($spec) ? $spec : [];

            if (array_key_exists($name, $folded)) {
                $example[$name] = $folded[$name];

                continue;
            }

            $supplied = $members[$name] ?? null;
            if ($supplied instanceof LiteralT) {
                $example[$name] = $supplied->value;

                continue;
            }

            $isRequired = in_array($name, $required, true);
            if ($supplied === null && ! $isRequired) {
                continue;
            }

            // A member the schema declares no type for has no truthful illustration — showing `"string"` for
            // what may well be a list would state something the code never said. A REQUIRED one is filled
            // anyway, since the alternative is dropping an example that would otherwise be complete.
            if ($isRequired || self::illustratable($spec, $context)) {
                [$example[$name], $typeDerived] = self::placeholder($name, $spec, $status, $context);
                if ($typeDerived) {
                    $derived[] = $name;
                }
            }
        }

        sort($derived, SORT_STRING);

        return [$example, $derived];
    }

    /**
     * Whether a member's schema says enough to build a placeholder from. A description and nothing else does
     * not: that's what an unresolved property type looks like once it reaches the document.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function illustratable(array $spec, RouteContext $context): bool
    {
        $effective = self::effectiveSpec($spec, $context);

        return array_key_exists('const', $effective)
            || isset($effective['type'])
            || self::statedValue($effective) !== null;
    }

    /**
     * A value the schema states for a member itself, or null when it states none. An `example` the author
     * wrote outranks a `default` the property merely carries: both are the app's own words, but only one was
     * written to illustrate. A null either side counts as unstated — a placeholder of `null` illustrates
     * nothing, and spatie only emits a non-null default in the first place.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function statedValue(array $spec): mixed
    {
        foreach (['example', 'default'] as $keyword) {
            if (($spec[$keyword] ?? null) !== null) {
                return $spec[$keyword];
            }
        }

        return null;
    }

    /**
     * The entry an `enum` illustrates itself with, wrapped so a null entry is distinguishable from a
     * schema stating no enum at all; null where it states none. The FIRST entry: a list's order is
     * authored, so every other reader of the document shows that same branch.
     *
     * Wrapped rather than bare because the two readings are opposite here, unlike in
     * {@see statedValue()}: an entry of `null` is a value the enum ADMITS, so falling through to the
     * type would illustrate the member with something the same two lines of schema reject.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array{mixed}|null
     */
    private static function enumValue(array $spec): ?array
    {
        $enum = $spec['enum'] ?? null;

        return is_array($enum) && $enum !== [] ? [array_values($enum)[0]] : null;
    }

    /**
     * A stand-in for one member: the `const` the schema pins, the real status for an integer `status`, else
     * a value that reads unmistakably as a placeholder for its declared type.
     *
     * Each answer says whether it came from the TYPE alone. The first two did not — a `const` is what the
     * schema pins and the status is what this response really answers with — so only the third is a member
     * about which nothing was read.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array{mixed, bool}
     */
    private static function placeholder(string $name, array $spec, int $status, RouteContext $context): array
    {
        if (array_key_exists('const', $spec)) {
            return [$spec['const'], false];
        }

        if ($name === 'status' && self::isType($spec['type'] ?? null, 'integer')) {
            return [$status, false];
        }

        return self::typePlaceholder($spec, $context, 0);
    }

    /**
     * The declared type's placeholder, following a `$ref` and descending into composites: an array with an
     * `items` schema gets exactly ONE element built the same way, so a list of objects renders as a list of
     * something rather than an empty pair of brackets, and an object gets its own required members. Nothing
     * is invented for a member the schema doesn't require. The depth cap is what keeps a self-referential
     * schema from unrolling forever.
     *
     * A value the schema states for the member itself is preferred to anything derived from its type: a
     * spatie property's `@example` or its PHP default reaches the component schema, and either is the app's
     * own word for what this member looks like — which `"string"` is not.
     *
     * Failing that, the keywords that NAME the member's value domain are read before its type is. An `enum`
     * and a `format` each say what the member holds, and `"string"` is a value the schema they describe
     * REJECTS — so a member the document declares two lines up as one of a fixed set of codes, or as a
     * date-time, would otherwise be illustrated by something the build's own example lint reports every
     * time (`lint.example-mismatch`), against an example its reader never wrote and cannot correct. A
     * numeric bound is read for the same reason and by the same table every other producer of a
     * representative value reads ({@see BoundedNumber}): it constrains a value and it also names one, so
     * `minimum: 5` is answered by 5 where `0` is a value that schema rejects.
     *
     * A constraint that names NO value stays unread: no constant satisfies an arbitrary `pattern`, and
     * nothing in this corpus states a length bound on a body member this tier fills. The lint remains the
     * backstop there.
     *
     * The second half of the answer is whether the value came from the type rather than from something
     * the schema STATED, which is the whole question {@see placeholder()} passes on. It is answered here
     * so there is one reading of it: a guard deciding it in a branch structure of its own would be a
     * second grammar to keep in step with this one. An `enum` entry and a format sample are on the DERIVED
     * side of that line: both come from the schema rather than from anything the code said, so the member
     * stays in the fill record {@see ResponseDraft::setExample()} keeps, and a collapse reading that record
     * still knows this arm never read it.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array{mixed, bool}
     */
    private static function typePlaceholder(array $spec, RouteContext $context, int $depth): array
    {
        $spec = self::effectiveSpec($spec, $context);

        if (array_key_exists('const', $spec)) {
            return [$spec['const'], false];
        }

        $stated = self::statedValue($spec);
        if ($stated !== null) {
            return [$stated, false];
        }

        $enumValue = self::enumValue($spec);
        if ($enumValue !== null) {
            return [$enumValue[0], true];
        }

        $format = $spec['format'] ?? null;
        $sample = is_string($format)
            ? FormatSamples::for($format, $context->representation()->formatSamples)
            : null;
        if ($sample !== null) {
            return [$sample, true];
        }

        $type = $spec['type'] ?? null;
        $deeper = $depth + 1;

        if (self::isType($type, 'array')) {
            $items = $spec['items'] ?? null;

            return [is_array($items) && $deeper < self::PLACEHOLDER_DEPTH
                ? [self::typePlaceholder($items, $context, $deeper)[0]]
                : [], true];
        }

        if (self::isType($type, 'object')) {
            // The depth cap answers `{}` rather than `[]` for the reason {@see objectPlaceholder()}
            // states.
            return [$deeper < self::PLACEHOLDER_DEPTH ? self::objectPlaceholder($spec, $context, $deeper) : new stdClass, true];
        }

        if (self::isType($type, 'integer') || self::isType($type, 'number')) {
            $bounded = BoundedNumber::nearest($spec, self::NUMBER_SEED, self::isType($type, 'integer'));

            // The keywords name no number to publish ({@see BoundedNumber}) and this tier has nowhere
            // to put that — dropping the member would drop the whole example. So the seed stands and the
            // build's example lint reports it against the schema beside it, which is the two lines of
            // the document the author wrote: a range nothing inhabits, or a bound no double could hold.
            return [$bounded === null ? self::NUMBER_SEED : $bounded[0], true];
        }

        return [self::isType($type, 'boolean') ? true : 'string', true];
    }

    /**
     * A nested object's required members only. An object requiring nothing comes out EMPTY rather than
     * inventing a key, which is still a truthful instance of it — and empty is a {@see stdClass}, never
     * `[]`, because a PHP array cannot spell `{}` and the array writes back as a JSON list the schema
     * beside it rejects (design §1, "The empty-object invariant").
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<string, mixed>|stdClass
     */
    private static function objectPlaceholder(array $spec, RouteContext $context, int $depth): array|stdClass
    {
        $properties = $spec['properties'] ?? null;
        if (! is_array($properties)) {
            return new stdClass;
        }

        $required = is_array($spec['required'] ?? null) ? $spec['required'] : [];

        $example = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            if (in_array($name, $required, true)) {
                $example[$name] = self::typePlaceholder(is_array($property) ? $property : [], $context, $depth)[0];
            }
        }

        return $example === [] ? new stdClass : $example;
    }

    /**
     * The constructor arguments the engine watched the payload object being built with: name → its folded
     * {@see LiteralT}, or the {@see UnknownT} meaning "supplied here, value not statically knowable". An
     * absent name means the argument wasn't passed at that call site.
     *
     * An OPTIONAL field is absent from this map too: it means the argument renders the key on some runs and
     * leaves it out on others, which is not the guarantee this map exists to state. Such a member falls back
     * to the schema — described there, and illustrated only if the schema says every response carries it.
     *
     * Keyed by CONSTRUCTOR ARGUMENT name. A Data class whose properties are remapped on the way out simply
     * matches nothing here, and the example falls back to the schema's required members.
     *
     * @return array<string, DType>
     */
    private static function suppliedMembers(mixed $membersArg): array
    {
        if (! $membersArg instanceof ArrayShapeT) {
            return [];
        }

        $members = [];
        foreach ($membersArg->fields as $field) {
            if ($field->optional) {
                continue;
            }
            $members[(string) $field->key] = $field->type;
        }

        return $members;
    }

    /** Whether a schema's `type` includes a name — it may be a nullable `[…, "null"]` array. */
    private static function isType(mixed $type, string $name): bool
    {
        return is_array($type) ? in_array($name, $type, true) : $type === $name;
    }

    /**
     * The schema a placeholder is actually derived from: the reference followed, a conjunction reduced to
     * the one schema its branches add up to ({@see conjoined()}), and a nullable branch
     * (`anyOf: [X, {type: null}]` — how a nullable `$ref` or composite is expressed) reduced to `X`, since
     * illustrating the null branch would show nothing. The first non-null branch wins for a wider union;
     * picking one member of a union is what an example is.
     *
     * A union's chosen branch is reduced again, because a nullable intersection arrives as a conjunction
     * INSIDE the branch.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<array-key, mixed>
     */
    private static function effectiveSpec(array $spec, RouteContext $context): array
    {
        $spec = self::conjoined(self::resolveSchema($spec, $context), $context);
        $branches = $spec['anyOf'] ?? $spec['oneOf'] ?? null;

        if (! is_array($branches)) {
            return $spec;
        }

        foreach ($branches as $branch) {
            if (is_array($branch) && ! self::isType($branch['type'] ?? null, 'null')) {
                return self::conjoined(self::resolveSchema($branch, $context), $context);
            }
        }

        return $spec;
    }

    /**
     * An `allOf` reduced to the single schema its branches add up to — how an intersection-typed member
     * reaches the document, and without this the member has no readable type at all and gets illustrated
     * `"string"`, a value every branch of it rejects.
     *
     * The rule: each branch's keywords accumulate in BRANCH ORDER and the first statement of a keyword
     * wins, except `properties`, which accumulate member by member under that same rule, and `required`,
     * which unions — for those two the conjunction of the branches IS their union.
     *
     * First-wins is exactly right for a keyword whose two spellings cannot both hold — `type`, `const`,
     * `format` — where the second is a contradiction the document already carries and the first branch is
     * the one every other reader of the document shows (the same authored-order reading `enum` and a
     * union's branches get); the example lint names it. It is NOT the conjunction of a numeric BOUND,
     * where two spellings have a well-defined answer (the higher floor, the lower ceiling) that this does
     * not compute, so a value legal under branch one and refused by branch two can come out. No producer
     * writes a body member as an `allOf` of bounds — the only in-tree producer writes object `$ref`
     * branches — and the divergence is a row of the adapter's example-agreement table rather than reach
     * nothing exercises.
     *
     * One level: a branch that is a boolean is no schema object and states nothing, and a branch carrying
     * a conjunction OF ITS OWN is DROPPED rather than followed, since a `$ref` cycle through one would
     * not end and nothing mints the shape. The answer therefore never states an `allOf`.
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<array-key, mixed>
     */
    private static function conjoined(array $spec, RouteContext $context): array
    {
        $branches = $spec['allOf'] ?? null;

        if (! is_array($branches)) {
            return $spec;
        }

        unset($spec['allOf']);

        foreach ($branches as $branch) {
            if (is_array($branch)) {
                $spec = self::conjoin($spec, self::resolveSchema($branch, $context));
            }
        }

        return $spec;
    }

    /**
     * One branch folded into what the conjunction has so far, under the rule {@see conjoined()} states.
     *
     * @param  array<array-key, mixed>  $into
     * @param  array<array-key, mixed>  $branch
     * @return array<array-key, mixed>
     */
    private static function conjoin(array $into, array $branch): array
    {
        foreach ($branch as $keyword => $value) {
            $existing = $into[$keyword] ?? null;

            if ($keyword === 'allOf') {
                // A conjunction of its own, which this reduction does not follow — carrying it forward
                // would leave the answer stating a keyword the reduction promises to have removed.
                continue;
            }

            if ($keyword === 'properties' && is_array($value)) {
                $into['properties'] = (is_array($existing) ? $existing : []) + $value;
            } elseif ($keyword === 'required' && is_array($value)) {
                $into['required'] = array_values(array_unique([
                    ...array_values(is_array($existing) ? $existing : []),
                    ...array_values($value),
                ], SORT_REGULAR));
            } elseif (! array_key_exists($keyword, $into)) {
                $into[$keyword] = $value;
            }
        }

        return $into;
    }

    /**
     * The body schema with a `#/components/schemas/*` reference followed, so `properties` and `required` are
     * visible. A hoisted Data class arrives as a bare `$ref`, which alone says nothing to build from.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private static function resolveSchema(array $schema, RouteContext $context): array
    {
        $ref = $schema['$ref'] ?? null;
        $prefix = '#/components/schemas/';

        if (! is_string($ref) || ! str_starts_with($ref, $prefix)) {
            return $schema;
        }

        $component = $context->components->schemas()[substr($ref, strlen($prefix))] ?? null;

        return is_array($component) ? $component : $schema;
    }

    /**
     * The invariant {@see example()} is built to satisfy, checked rather than assumed: an example still
     * missing a required member would fail validation against its own schema, so it's dropped instead.
     *
     * @param  array<string, mixed>  $example
     * @param  array<array-key, mixed>  $schema
     */
    private static function satisfies(array $example, array $schema): bool
    {
        $required = $schema['required'] ?? [];
        if (! is_array($required)) {
            return true;
        }

        foreach ($required as $member) {
            if (! is_string($member) || ! array_key_exists($member, $example)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only top-level members that folded to a literal go in; widened scalars, dynamic bodies and nested
     * shapes are omitted rather than invented. Declaration order, so it's deterministic. Empty → no
     * example emitted.
     *
     * @return array<string, string|int|float|bool>
     */
    private static function assembleExample(DType $payload): array
    {
        if (! $payload instanceof ArrayShapeT || $payload->isList) {
            return [];
        }

        $example = [];
        foreach ($payload->fields as $field) {
            if ($field->type instanceof LiteralT) {
                $example[(string) $field->key] = $field->type->value;
            }
        }

        return $example;
    }
}
