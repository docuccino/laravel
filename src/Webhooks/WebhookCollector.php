<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Webhooks;

use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Webhook;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\PlainText;
use Docuccino\Core\TypeGrammar\DocBlockReader;
use Docuccino\Laravel\Routing\AttributeCollector;
use Docuccino\Laravel\Support\DeclaredClasses;
use Docuccino\Laravel\Support\UnknownDocumentPins;
use ReflectionClass;

/**
 * Reads `documents.*.webhooks.dir` into the `#[Webhook]` declarations that document publishes.
 *
 * Discovery is a function of the directory's contents and nothing else: files are read in sorted
 * order, classes are answered sorted by FQCN, and two classes contesting one name are settled by the
 * lower FQCN rather than by whichever was met first. `#[ExcludeFromDocs]` and `#[InDocs]` filter a
 * webhook class exactly as they filter a controller.
 *
 * @internal
 */
final readonly class WebhookCollector
{
    public function __construct(
        private string $basePath,
        private DocBlockReader $docblocks = new DocBlockReader,
        private AttributeCollector $attributes = new AttributeCollector,
    ) {}

    /**
     * Where the class was written, relative to the project root — never an absolute path.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function sourceOf(ReflectionClass $class): ?Source
    {
        $file = $class->getFileName();
        if ($file === false) {
            return null;
        }

        $line = $class->getStartLine();

        return new Source(
            (new RootRelativeSourcePathResolver($this->basePath))->relative($file),
            $line > 0 ? $line : null,
            $class->getShortName(),
        );
    }

    /**
     * @return array{0: list<WebhookDeclaration>, 1: list<Diagnostic>}
     */
    public function collect(DocumentConfig $document): array
    {
        $configured = $document->webhooksDir();
        if ($configured === null) {
            return [[], []];
        }

        $dir = ConfinedPath::configuredDir($this->basePath, $configured);
        if ($dir === null) {
            return [[], [new Diagnostic(
                severity: Severity::Warning,
                code: 'webhook.dir-escapes-base',
                message: sprintf('The webhook directory "%s" does not name a path inside the application and was ignored.', PlainText::of($configured)),
            )]];
        }

        if (! is_dir($dir)) {
            return [[], [new Diagnostic(
                severity: Severity::Warning,
                code: 'webhook.dir-missing',
                message: sprintf('The configured webhook directory "%s" does not exist.', PlainText::of($configured)),
                help: 'Create it or unset documents.*.webhooks.dir.',
            )]];
        }

        $diagnostics = [];
        $declarations = [];

        // Held for the walk rather than per class: an `#[InDocs]` key nobody configured is one mistake
        // however many webhook classes carry it ({@see UnknownDocumentPins}).
        $pins = new UnknownDocumentPins;

        foreach (DeclaredClasses::in($dir) as $class) {
            $declaration = $this->declare($class, $document, $diagnostics, $pins);
            if ($declaration !== null) {
                $declarations[] = $declaration;
            }
        }

        $diagnostics = [...$diagnostics, ...$pins->take()];

        return [$this->deduped($declarations, $diagnostics), $diagnostics];
    }

    /**
     * @param  class-string  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function declare(string $class, DocumentConfig $document, array &$diagnostics, UnknownDocumentPins $pins): ?WebhookDeclaration
    {
        $reflection = new ReflectionClass($class);

        $attributes = $this->attributesOf($reflection, $diagnostics);
        $webhook = $attributes->first(Webhook::class);
        if ($webhook === null || $attributes->has(ExcludeFromDocs::class)) {
            return null;
        }

        $inDocs = $attributes->first(InDocs::class);
        if ($inDocs !== null) {
            $pins->record($inDocs, $class);

            if (! in_array($document->key, $inDocs->documents, true)) {
                return null;
            }
        }

        $name = trim($webhook->name);
        if ($name === '') {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'webhook.name-invalid',
                message: sprintf('%s carries a #[Webhook] with no name, so it is not in the document — a webhook is published under its name.', PlainText::of($class)),
                help: 'Give the attribute the name the receiving endpoint is documented under, e.g. #[Webhook(\'invoice.paid\')].',
            );

            return null;
        }

        $method = strtolower(trim($webhook->method));
        if (! in_array($method, PathItem::METHODS, true)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'webhook.method-unknown',
                message: sprintf('The webhook "%s" asks for the method "%s", which OpenAPI has no path-item member for; it is documented as POST.', PlainText::of($name), PlainText::of($webhook->method)),
                help: sprintf('Use one of %s.', implode(', ', PathItem::METHODS)),
            );
            $method = 'post';
        }

        $prose = $this->docblocks->read($reflection->getDocComment() ?: null);

        return new WebhookDeclaration(
            class: $class,
            name: $name,
            method: $method,
            payload: $webhook->payload ?? '\\'.$class,
            mediaType: $webhook->mediaType,
            attributes: $attributes,
            summary: $prose['summary'],
            description: $prose['description'],
            deprecated: $prose['deprecated'],
            deprecationReason: $prose['deprecationReason'],
            files: DeclarationFiles::forClass($reflection),
            source: $this->sourceOf($reflection),
        );
    }

    /**
     * One declaration per (name, method). Two classes contesting a slot keep the lower FQCN — an
     * answer derived from the pair rather than from which was met first, so adding a third webhook
     * cannot move which of the two the document publishes.
     *
     * @param  list<WebhookDeclaration>  $declarations
     * @param  list<Diagnostic>  $diagnostics
     * @return list<WebhookDeclaration>
     */
    private function deduped(array $declarations, array &$diagnostics): array
    {
        $kept = [];

        foreach ($declarations as $declaration) {
            $slot = $declaration->name."\0".$declaration->method;
            $holder = $kept[$slot] ?? null;

            if ($holder === null) {
                $kept[$slot] = $declaration;

                continue;
            }

            [$winner, $loser] = strcmp($holder->class, $declaration->class) <= 0
                ? [$holder, $declaration]
                : [$declaration, $holder];

            $kept[$slot] = $winner;
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'webhook.name-collision',
                message: sprintf(
                    'The webhook "%s" is claimed by both %s and %s for %s; %s is the one in the document.',
                    PlainText::of($declaration->name),
                    PlainText::of($winner->class),
                    PlainText::of($loser->class),
                    strtoupper(PlainText::of($declaration->method)),
                    PlainText::of($winner->class),
                ),
                routeSignature: $declaration->signature(),
                help: 'Give one of them a name of its own — a webhook name is the contract a consumer subscribes to.',
            );
        }

        return array_values($kept);
    }

    /**
     * The Docuccino attributes on the class itself — {@see AttributeCollector::collectOne()}, which
     * walks nowhere, so a `#[Webhook]` is never inherited from a base event.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function attributesOf(ReflectionClass $class, array &$diagnostics): AttributeSet
    {
        return $this->attributes->collectOne(
            $class,
            $class->getName(),
            static function (Diagnostic $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );
    }
}
