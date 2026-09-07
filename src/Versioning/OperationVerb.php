<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * A verb whose subject is an OPERATION rather than a schema, as {@see ApiVersionTransformer} applies it.
 *
 * The distinction is not a tidying: a {@see VersionVerb} names a class, resolves it to ONE node
 * identity, and is applied wherever the document carries that identity — the walk expands `$ref`s and
 * forks a shared component where a scope narrows it. A parameter has none of that. It is flattened onto
 * `operation.parameters[]` under an identity that is a function of the operation AND of the parameter's
 * own name, so there is no single node to look for, nothing shared to fork, and the name itself is part
 * of what identifies the thing being renamed.
 *
 * That is also why `#[AppliesTo]` degrades to a plain filter for these. A schema verb's scope has two
 * branches — narrow to some operations by giving them a private copy, or rename the shared component
 * where the scope covers all of them — and neither has an analogue here, because a parameter belongs to
 * one operation already. A scope decides which operations are visited and nothing else.
 *
 * @internal
 */
interface OperationVerb
{
    /**
     * What this verb names, as a diagnostic spells it — `the query parameter "search"`.
     */
    public function declares(): string;

    /**
     * The edit, on one operation. `$outcome` is that operation's OWN answer — the transformer hands a
     * fresh one to every operation, because two operations are two declarations rather than two copies
     * of one node — and an implementation only ever raises it, so one operation's several parameters
     * cannot undo each other's answer.
     *
     * `$scope` is what the operation's nodes belong to: its own identity where it has one, and where it
     * stands where it does not — the same fallback a forked schema's ids are re-minted against. A verb
     * that moves a name an identity is derived FROM has to re-mint that identity, and this is what it
     * mints against.
     *
     * @param  array<array-key, mixed>  $operation
     * @return array<array-key, mixed>
     */
    public function apply(array $operation, string $scope, IdentityGenerator $identity, VerbOutcome &$outcome): array;

    /**
     * ONE operation the verb would not edit, and `$operation` is what a selector calls it. Per
     * operation rather than per change, because a refusal is a fact about the operation it was refused
     * for: an unscoped rename that edits `GET /b` and refuses `GET /a` has left the version document
     * spelling one parameter two ways, and a report that collapsed the two would say nothing at all.
     */
    public function refused(string $operation, VersionChange $change): Diagnostic;

    /**
     * No operation in scope declared what the verb names — the one report the whole walk owes rather
     * than any one operation, since most operations in scope will not declare it.
     */
    public function unreached(VersionChange $change): Diagnostic;
}
