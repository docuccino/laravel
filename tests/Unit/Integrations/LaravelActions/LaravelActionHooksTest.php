<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\LaravelActionHooks;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ArchiveArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\ExplicitMethodAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\RecordNoteAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\SimpleAction;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\WithAttributesAction;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Decorators\ControllerDecorator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Workbench\App\Http\Controllers\FormController;

/*
 * The lists are the installed package's own, read off its source: a hook the package calls that is missing
 * here is a header it reads that no document publishes, and one listed that it never calls is a header
 * published that the server never reads.
 */
it('lists every method of the action the installed package calls by name while it validates', function (): void {
    $source = '';
    foreach ((new ReflectionClass(ActionRequest::class))->getTraits() as $trait) {
        $source .= declarationSource($trait);
    }
    $source .= declarationSource(new ReflectionClass(ActionRequest::class));

    preg_match_all("/hasMethod\\('(\\w+)'\\)/", $source, $matches);
    $called = array_values(array_unique($matches[1]));
    sort($called, SORT_STRING);

    expect(count($called))->toBeGreaterThan(10)
        ->and($called)->toBe(LaravelActionHooks::HOOKS);
});

it('lists exactly the methods whose presence makes the installed package validate', function (): void {
    preg_match_all("/hasMethod\\('(\\w+)'\\)/", declarationSource(new ReflectionMethod(ControllerDecorator::class, 'hasAnyValidationMethod')), $matches);
    $methods = $matches[1];
    sort($methods, SORT_STRING);

    expect($methods)->not->toBeEmpty()
        ->and($methods)->toBe(LaravelActionHooks::VALIDATION_METHODS);
});

it('lists exactly the hooks the installed package validates with in place of its own input or validator', function (): void {
    // A hook stands in for the default where the package asks for it by name and, without it, builds the
    // default itself: `hasMethod('x') ? … : $this->getDefault…()`, or the same as an if/else.
    $source = '';
    foreach ((new ReflectionClass(ActionRequest::class))->getTraits() as $trait) {
        $source .= declarationSource($trait)."\n";
    }
    $statements = (new ParserFactory)->createForHostVersion()->parse('<?php '.$source) ?? [];
    $finder = new NodeFinder;
    $makesDefault = static fn (Node|array|null $branch): bool => $branch !== null && $finder->findFirst($branch, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
        && $node->name instanceof Node\Identifier
        && preg_match('/^(get|create)Default/', $node->name->toString()) === 1) !== null;

    $overrides = [];
    foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\Ternary || $node instanceof Node\Stmt\If_) as $choice) {
        $condition = $choice instanceof Node\Expr\Ternary || $choice instanceof Node\Stmt\If_ ? $choice->cond : null;
        $default = $choice instanceof Node\Expr\Ternary ? $choice->else : ($choice instanceof Node\Stmt\If_ ? $choice->else : null);
        $asked = $condition instanceof Node\Expr\MethodCall && $condition->name instanceof Node\Identifier && $condition->name->toString() === 'hasMethod'
            ? ($condition->getArgs()[0]->value ?? null)
            : null;
        if ($asked instanceof Node\Scalar\String_ && $makesDefault($default)) {
            $overrides[] = $asked->value;
        }
    }
    sort($overrides, SORT_STRING);

    expect($overrides)->not->toBeEmpty()
        ->and($overrides)->toBe(LaravelActionHooks::VALIDATION_OVERRIDES)
        ->and(array_diff(LaravelActionHooks::VALIDATION_OVERRIDES, LaravelActionHooks::HOOKS))->toBe([]);
});

it('runs the hooks only where the package validates for the dispatch', function (string $class, string $method, bool $expected): void {
    expect(LaravelActionHooks::runsHooks($class, $method))->toBe($expected);
})->with([
    'rules() and authorize() on handle' => [PublishArticleAction::class, 'handle', true],
    'rules() on asController' => [RecordNoteAction::class, 'asController', true],
    // Neither a rule nor a gate nor a validator hook: the package never validates, so nothing is called.
    'no validation method' => [SimpleAction::class, 'handle', false],
    'no validation method on asController' => [ArchiveArticleAction::class, 'asController', false],
    'explicit method' => [ExplicitMethodAction::class, 'store', false],
    'WithAttributes action' => [WithAttributesAction::class, 'handle', false],
    'non-action controller' => [FormController::class, '__invoke', false],
]);
