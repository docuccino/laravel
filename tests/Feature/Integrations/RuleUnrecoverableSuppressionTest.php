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
 * A field whose rules() cannot be statically recovered is reported as `validation.rule-unrecoverable`.
 * What was lost differs by whether ANOTHER producer documents the field — a spatie Data property typed
 * `UploadedFile`, say, which documents from its type regardless of its dynamic rules() — so the message
 * differs too: the field is omitted outright, or kept without the constraints its rules stated. What is
 * never right is silence — an unfoldable `Rule::in()` erases a field's allow-list, and the build has to
 * say so (SpatieDataRealShapeTest pins that recovery half against the real engine).
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

it('reports a field documented by another producer as a loss of constraints, not an omission', function (): void {
    $messages = unrecoverableDiagnostics(documentedElsewhere: ['file']);

    // `file` is documented elsewhere (e.g. its UploadedFile type), so only its constraints were lost.
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('"file"')
        ->and($messages[0])->toContain('documented from its type alone')
        ->and($messages[0])->not->toContain('omitted from the request schema')
        ->and($messages[1])->toContain('"secret"')
        ->and($messages[1])->toContain('omitted from the request schema');
});

it('still fires for every unrecoverable field when nothing recovered them', function (): void {
    $messages = unrecoverableDiagnostics(documentedElsewhere: []);

    expect($messages)->toHaveCount(2)
        ->and(implode("\n", $messages))->toContain('"file"')
        ->and(implode("\n", $messages))->toContain('"secret"')
        ->and(implode("\n", $messages))->not->toContain('documented from its type alone');
});
