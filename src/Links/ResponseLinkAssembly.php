<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Links\BodyGive;
use Docuccino\Core\Links\OperationLinks;
use Docuccino\Core\Links\ResponseLinks;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;
use Docuccino\Laravel\Versioning\RenameEdit;
use Docuccino\Laravel\Versioning\SchemaFacet;
use Docuccino\Laravel\Versioning\VersionChangeCollector;

/**
 * Turns what each operation recorded about its links ({@see ResponseLinkFacts}) into response `links`,
 * through core's {@see ResponseLinks}. The observation rides the fragment and the join does not, so a warm
 * build writes the links a cold one does.
 *
 * After API versioning, so a link never names an operation an older version does not serve, nor a member
 * it renamed: the join reads the document that will be emitted. The edge is explicit because both are
 * late, and a tie between them would otherwise go to the class name. A member a later version renamed is
 * no member to link on at all: the older document may give its name to another field, which the pointer
 * would then read as if it were the same one.
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE, after: [ApiVersionTransformer::class])]
final class ResponseLinkAssembly implements DocumentTransformer, RouteNoteCollector
{
    /** @var array<string, list<string>> signature → the facts its routes recorded */
    private array $recorded = [];

    public function __construct(private readonly VersionChangeCollector $changes) {}

    public function channel(): string
    {
        return ResponseLinkFacts::CHANNEL;
    }

    public function forget(): void
    {
        $this->recorded = [];
    }

    public function collect(string $key, array $values): void
    {
        foreach ($values as $value) {
            $this->recorded[$key][] = $value;
        }
    }

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if ($this->recorded === []) {
            return;
        }

        $renamed = $this->renamed($context);
        $facts = [];
        foreach ($this->recorded as $signature => $values) {
            $facts[$signature] = self::merged(array_map(OperationLinks::fromJson(...), $values), $renamed);
        }

        [$doc, $diagnostics] = ResponseLinks::write($document->toArray(), $facts);

        foreach ($diagnostics as $diagnostic) {
            $context->report($diagnostic);
        }

        $document->replace($doc);
    }

    /**
     * One operation's facts from the notes its routes left — more than one where two routes share a
     * method and path, which the assembly has already reported as a contested slot.
     *
     * @param  list<OperationLinks>  $notes
     * @param  array<string, array<string, true>>  $renamed
     */
    private static function merged(array $notes, array $renamed): OperationLinks
    {
        $takes = $gives = $declared = $unreadable = [];

        foreach ($notes as $note) {
            $takes += $note->takes;
            array_push($gives, ...array_filter($note->gives, static fn (BodyGive $give): bool => ! isset($renamed[$give->body][$give->member()])));
            array_push($declared, ...$note->declared);
            array_push($unreadable, ...$note->unreadable);
        }

        return new OperationLinks($takes, $gives, $declared, $unreadable);
    }

    /**
     * Today's names of the response members a change after this document's version renames, by the class
     * whose shape carries them — read from the changes {@see ApiVersionTransformer} applies.
     *
     * @return array<string, array<string, true>>
     */
    private function renamed(DocumentContext $context): array
    {
        $version = $context->config->declaresApiVersion() ? $context->config->apiVersion() : null;
        if ($version === null) {
            return [];
        }

        $renamed = [];
        foreach ($this->changes->collect($context->config)->after($version) as $change) {
            foreach ($change->verbs as $verb) {
                if ($verb instanceof RenameEdit && $verb->facet() === SchemaFacet::Response) {
                    $renamed[ltrim($verb->schema(), '\\')][$verb->retiredMember()] = true;
                }
            }
        }

        return $renamed;
    }
}
