<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Exceptions\DeclaredErrorComponent;

/**
 * Turns the action's signalled exceptions into error responses (design §Errors): each runs through the
 * resolved {@see ExceptionToResponse} chain (first supports() wins) and merges in via
 * {@see ResponseDraftApplier}. Skipped when `error_responses => 'none'`.
 *
 * Also the one place `#[ErrorComponent]` is read, so the name an application declares reaches the
 * response through the same `claimComponentName()` every producer uses ({@see applyDeclarations()}).
 */
final class ErrorResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none') {
            return;
        }

        /** @var array<string, array<string, DeclaredErrorComponent>> $declared */
        $declared = [];
        /** @var array<string, DeclaredErrorComponent> $illegal */
        $illegal = [];

        foreach ($context->analysis()->throws as $throw) {
            if ($throw->disposition !== ThrowDisposition::Signal) {
                continue;
            }

            // Whether an exception names its own component is answered by its whole hierarchy, so the
            // hierarchy's files key this route's fragment: an attribute added to a BASE class has to
            // rebuild every route that throws a subclass, and a warm build that missed it would publish
            // a different name from a cold one.
            $context->recordDependencyFiles(DeclarationFiles::of($throw->exceptionFqcn));

            $mapped = $context->mapThrow($throw);
            if ($mapped === null) {
                continue;
            }

            $this->applier->apply($operation, $mapped->draft, $mapped->mapper->producer(), $this->throwSource($context, $throw));

            $declaration = DeclaredErrorComponent::on($throw->exceptionFqcn);
            if ($declaration === null) {
                continue;
            }

            // A name no key could carry is not a name, so it neither claims a response nor contests one.
            // Keyed by the mistake rather than the throw, so a class signalled from two sites is one
            // report, and sorted below so what the route says never depends on which site came first.
            if (! ComponentNames::isLegal($declaration->name)) {
                $illegal[$declaration->declaredBy."\0".$declaration->name] = $declaration;

                continue;
            }

            // Two classes declaring ONE name for one status agree on what to publish, so this is no
            // contest — but they disagree on whose declaration the provenance records, and keeping
            // whichever the engine reported last would put throw order into the emitted bytes. Lowest
            // FQCN wins, which is a fact about the two classes and not about the order they arrived in.
            $winner = $declared[$mapped->draft->status][$declaration->name] ?? null;
            if ($winner === null || strcmp($declaration->declaredBy, $winner->declaredBy) < 0) {
                $declared[$mapped->draft->status][$declaration->name] = $declaration;
            }
        }

        ksort($illegal);
        foreach ($illegal as $declaration) {
            $this->reportIllegalName($context, $declaration);
        }

        $this->applyDeclarations($operation, $context, $declared);
    }

    /**
     * Publish each declared name on the response its exception produced. Two exceptions declaring
     * DIFFERENT names for one status describe one response that can only carry one name, so neither
     * takes it ({@see reportContest()}).
     *
     * @param  array<string, array<string, DeclaredErrorComponent>>  $declared  status → declared name → its declaration
     */
    private function applyDeclarations(OperationDraft $operation, RouteContext $context, array $declared): void
    {
        foreach ($declared as $key => $declarations) {
            $status = (string) $key;

            if (count($declarations) > 1) {
                $this->reportContest($context, $status, $declarations);

                continue;
            }

            // Exactly one, since a status only appears here once something declared for it.
            foreach ($declarations as $declaration) {
                $response = $operation->response($status);

                // A response that is a reference states no body of its own, so it is not this operation's
                // to name — the component it points at was named where it was defined. What a declaration
                // may take from a body it CAN name: {@see DeclaredErrorComponent::mayReplace()}.
                if ($response->resolvedField('$ref') === null && DeclaredErrorComponent::mayReplace($response->componentClaim(), $status)) {
                    $response->claimComponentName($declaration->name, Contribution::attribute($this->declarationSource($context, $declaration)));
                }
            }
        }
    }

    /** Where the winning `#[ErrorComponent]` was written — the class that declared it, not the throw site. */
    private function declarationSource(RouteContext $context, DeclaredErrorComponent $declaration): ?Source
    {
        return $context->sourceAt(
            new SourceLocation($declaration->file ?? '', $declaration->line),
            $declaration->declaredBy,
        );
    }

    /**
     * One warning per class that declared a name no `$ref` could point at. `claimComponentName()` drops
     * such a name at the write and says nothing, which leaves the author of the attribute with a line of
     * code that does nothing and no reason why — so the adapter, which unlike a draft has somewhere to
     * say it, catches it where it reads it and names the class, the file and the name it read.
     */
    private function reportIllegalName(RouteContext $context, DeclaredErrorComponent $declaration): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.error-component-invalid',
            message: sprintf(
                '%s declares #[ErrorComponent("%s")], which is not a name an OpenAPI component key can carry, so the attribute names nothing and the response keeps the name it would have had.',
                $declaration->declaredBy,
                self::printable($declaration->name),
            ),
            source: $this->declarationSource($context, $declaration),
            routeSignature: $context->route->signature(),
            help: 'A component key is letters, digits, ".", "_" and "-" only. A reason phrase as one word — "NotFound", "TooManyRequests" — is what reads best as a generated client\'s type.',
        ));
    }

    /**
     * The declared name as a diagnostic may quote it. Nothing validated the string an attribute carries,
     * and a diagnostic is read on a terminal, so a control character in it would move the cursor rather
     * than be read. Everything else passes through — the author has to recognise what they wrote.
     */
    private static function printable(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\x%02X', ord($match[0])),
            $value,
        );
    }

    /**
     * One warning per status two declarations disagree over. Handing the response to whichever exception
     * the engine reported first would make the published name — a generated client's type name — a
     * function of encounter order, so the status keeps its default name and the author is told which two
     * classes to reconcile.
     *
     * @param  array<string, DeclaredErrorComponent>  $declarations
     */
    private function reportContest(RouteContext $context, string $status, array $declarations): void
    {
        ksort($declarations);

        $claims = [];
        foreach ($declarations as $name => $declaration) {
            $claims[] = sprintf('%s names it "%s"', $declaration->declaredBy, $name);
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.error-component-contested',
            message: sprintf(
                'Exceptions this action signals declare different component names for its %s response (%s), which can carry only one, so the default name stands.',
                $status,
                implode('; ', $claims),
            ),
            source: $context->actionSource(),
            routeSignature: $context->route->signature(),
            help: sprintf(
                'One response carries one name. Keep #[ErrorComponent] on the exception the %s response really is and drop it from the others, or document the errors under statuses of their own. Where the bodies genuinely differ, register an ExceptionToResponse that builds and names each one.',
                $status,
            ),
        ));
    }

    /**
     * The throw site (first call-chain frame), falling back to the action when the engine had no usable
     * location — so an explicit throw carries a source just like a synthesized one, never none.
     */
    private function throwSource(RouteContext $context, ThrownException $throw): ?Source
    {
        $frame = $throw->callChain[0] ?? null;
        if ($frame !== null && $frame->location->file !== '') {
            return $context->sourceAt($frame->location, $frame->symbol === '' ? null : $frame->symbol);
        }

        return $context->actionSource();
    }
}
