<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;
use Docuccino\Laravel\Tests\Fixtures\FormRequest\SuppressibleRulesData;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * A field whose rules() cannot be statically recovered is normally reported as
 * `validation.rule-unrecoverable` ("omitted from the request schema"). But when ANOTHER producer
 * already documents that field — e.g. a spatie Data property typed `UploadedFile`, which is
 * documented from its type regardless of its dynamic rules() — the warning is stale and misleading, so
 * it is suppressed. It still fires for a field nothing else recovered.
 */
function unrecoverableDiagnostics(array $documentedElsewhere): array
{
    // Script the rules() trace: both `file` and `secret` have closure rules → unrecoverable.
    $trace = static function (TraceVisitor $visitor): void {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(
            "<?php\nreturn ['file' => [fn () => true], 'secret' => [fn () => true]];\n",
        ) ?? [];
        $scope = new StubTraceScope(new UnknownT('n/a'));
        $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
        {
            public function __construct(
                private readonly TraceVisitor $visitor,
                private readonly StubTraceScope $scope,
            ) {}

            public function enterNode(Node $node): ?int
            {
                $this->visitor->enterNode($node, $this->scope);

                return null;
            }
        });
        $traverser->traverse($ast);
    };

    $engine = new StubTypeEngine(traces: [SuppressibleRulesData::class.'::rules' => $trace]);

    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/uploads'),
        actionRef: new ActionRef('', 'App\\C', 'store'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    (new RulesFromClass)->analyse($context, SuppressibleRulesData::class, $documentedElsewhere);

    return array_values(array_map(
        static fn ($d): string => $d->message,
        array_filter($context->components->diagnostics(), static fn ($d): bool => $d->code === 'validation.rule-unrecoverable'),
    ));
}

it('suppresses the diagnostic for a field documented by another producer', function (): void {
    $messages = unrecoverableDiagnostics(documentedElsewhere: ['file']);

    // Only `secret` remains — `file` is documented elsewhere (e.g. its UploadedFile type).
    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('"secret"')
        ->and(implode("\n", $messages))->not->toContain('"file"');
});

it('still fires for every unrecoverable field when nothing recovered them', function (): void {
    $messages = unrecoverableDiagnostics(documentedElsewhere: []);

    expect($messages)->toHaveCount(2)
        ->and(implode("\n", $messages))->toContain('"file"')
        ->and(implode("\n", $messages))->toContain('"secret"');
});
