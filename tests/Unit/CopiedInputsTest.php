<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\CopiedInputParameters;
use Docuccino\Laravel\Integrations\FormRequest\CopiedInputs;
use Docuccino\Laravel\Integrations\FormRequest\NullRejection;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AfterHooksAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AfterHooksRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AliasedRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AliasedTraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedAliasedTraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedChosenTraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedHookAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedTraitHookAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\AttributedTraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\BagWriteRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\BaseCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\BaseRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CertainCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ChosenTraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CompactedRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ConstructedHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerActionRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerClassRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerKeyRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ContainerMakeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesDeviceHere;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesIdempotencyKey;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesKeyAttributedInAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesKeyInAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesLocale;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesRegion;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesTenant;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopiesTraceId;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\CopyingAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\DefaultValidatorRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\EarlyReturnRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\FacadeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\GlobalRequestRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HandedBagRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HandedValidatorRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HandsRequestOnAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HeldRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\HelperCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\InheritedCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\InlineCopyController;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\NamedRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OffsetWriteRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OtherServiceRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnAllRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnContainerRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnMethodAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnStaticCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidateResolvedRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidationDataAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidationDataRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidatorAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\OwnValidatorRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ParentCallRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ReassignedRequestAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\RepeatedCopiesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ReplacedInputRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ResolvedRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SafeUsesRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SameFileChosenHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SameRequestTwiceAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ServiceHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SetDataValidatorAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SetDataValidatorRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\SpreadMergeRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\StaticHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\TappedHandOffRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\TraitHookAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\TraitHookRequest;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\TwoRequestsAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\UnhandedAction;
use Docuccino\Laravel\Tests\Fixtures\CopiedInputs\ValidatorInstanceRequest;
use Docuccino\Laravel\Tests\Fixtures\LaravelActions\PublishArticleAction;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreDialledNoticeRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreRoutedNoticeRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidatesWhenResolvedTrait;
use Illuminate\Validation\Validator as ValidatorInstance;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Workbench\App\Enums\WidgetStatus;

/**
 * `merge()` in `prepareForValidation()` writes over whatever the body sent, so a key it fills from a header,
 * a query value or a route parameter validates THAT part of the request. The reader trusts only a copy
 * nothing else in the hook can write, because a key it moves off the body on a guess is a field a client is
 * told not to send when the server may read it.
 */
function copiedInputContext(): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], '/api/notes'),
        actionRef: new ActionRef('notes.php', 'NoteController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

/**
 * A FormRequest, a trait method it takes as its hook, an action and a controller, each with its visibility on
 * the line above the keyword, written to a file of their own and loaded; answers their namespace.
 */
function copiedInputsSplitModifiers(): string
{
    static $namespace = null;
    if ($namespace === null) {
        $namespace = 'DocuccinoSplitModifiers'.dechex(random_int(0, PHP_INT_MAX));
        $file = sys_get_temp_dir().'/docuccino-split-modifiers-'.uniqid('', true).'.php';
        file_put_contents($file, <<<PHP
            <?php
            namespace $namespace;
            use Illuminate\\Foundation\\Http\\FormRequest;
            use Illuminate\\Http\\Request;
            use Lorisleiva\\Actions\\ActionRequest;
            use Lorisleiva\\Actions\\Concerns\\AsAction;
            class SplitRequest extends FormRequest {
                protected
                function prepareForValidation(): void { \$this->merge(['key' => \$this->header('Idempotency-Key')]); }
            }
            trait SplitCopies {
                protected
                function copyKey(): void { \$this->merge(['key' => \$this->header('Idempotency-Key')]); }
            }
            class SplitAliasedRequest extends FormRequest {
                use SplitCopies { copyKey as protected prepareForValidation; }
            }
            class SplitAction {
                use AsAction;
                public function rules(): array { return ['key' => 'required|uuid']; }
                public
                function prepareForValidation(ActionRequest \$request): void { \$request->merge(['key' => \$request->header('Idempotency-Key')]); }
                public function handle(): void {}
            }
            class SplitController {
                public
                function store(Request \$request): void {
                    \$request->merge(['key' => \$request->header('Idempotency-Key')]);
                    \$request->validate(['key' => 'required|uuid']);
                }
            }
            PHP);
        require $file;
    }

    return $namespace;
}

function inlineCopyContext(string $method): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], '/api/notes'),
        actionRef: new ActionRef((string) (new ReflectionClass(InlineCopyController::class))->getFileName(), InlineCopyController::class, $method),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

it('reads a copy from each part of the request by the literal name it is read under', function (): void {
    // `header('X-Locale', 'en')` answers its default when the header is absent, so the key is not what the
    // header sent; a dynamic, nested or non-`$this` read, and a transformed one, name no one parameter.
    expect((new CopiedInputs)->of(copiedInputContext(), CertainCopiesRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
        'page' => ['in' => 'query', 'name' => 'page'],
        'post' => ['in' => 'path', 'name' => 'post'],
        'trace' => ['in' => 'header', 'name' => 'X-Trace-Id'],
    ]);
});

it('keeps a copy beside a merge into a service that is not the request', function (): void {
    expect((new CopiedInputs)->of(copiedInputContext(), OtherServiceRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('trusts only a copy no other write can reach', function (): void {
    // A second merge, a fill-if-missing, a branch and a closure each write their key on some requests.
    expect((new CopiedInputs)->of(copiedInputContext(), RepeatedCopiesRequest::class))->toBe([
        'kept' => ['in' => 'header', 'name' => 'X-Kept'],
    ]);
});

it('keeps a copy beside uses of the request that hand it nowhere', function (): void {
    // A value read off it, its class, `isset()`: none of these reaches code that could merge over the copy.
    expect((new CopiedInputs)->of(copiedInputContext(), SafeUsesRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('trusts no copy where something writes keys it cannot name', function (string $class): void {
    expect((new CopiedInputs)->of(copiedInputContext(), $class))->toBe([]);
})->with([
    'an early return' => [EarlyReturnRequest::class],
    'replace()' => [ReplacedInputRequest::class],
    "a call into the request's own code" => [HelperCallRequest::class],
    'a write into a bag' => [BagWriteRequest::class],
    'an array-access write' => [OffsetWriteRequest::class],
    'a spread merge' => [SpreadMergeRequest::class],
    "the base's hook, run by parent::" => [ParentCallRequest::class],
    // Code handed the request, or one of its input bags, can merge over the copy after it.
    'itself handed to a static helper' => [StaticHandOffRequest::class],
    'itself handed to a function' => [TappedHandOffRequest::class],
    'itself handed to a service' => [ServiceHandOffRequest::class],
    'itself handed to a constructor' => [ConstructedHandOffRequest::class],
    'itself held in a variable' => [AliasedRequest::class],
    'its JSON bag handed on' => [HandedBagRequest::class],
    'its own code, called through $this::' => [OwnStaticCallRequest::class],
    // The request it was built from shares its JSON bag.
    'a merge through request()' => [GlobalRequestRequest::class],
    'a merge through the facade' => [FacadeRequest::class],
    // …and so does every way of asking the container for it.
    "a merge through app('request')" => [ContainerKeyRequest::class],
    "a merge through resolve('request')" => [ResolvedRequest::class],
    'a merge through app(Request::class)' => [ContainerClassRequest::class],
    "a merge through app()->make() of Symfony's request" => [ContainerMakeRequest::class],
    'a merge through its own container' => [OwnContainerRequest::class],
    'its own validationData()' => [OwnValidationDataRequest::class],
    // `validator()` builds the validator in place of the one that reads the merged input.
    'its own validator()' => [OwnValidatorRequest::class],
    // Each of the framework's steps from resolving the request to the data its validator is built over.
    'its own createDefaultValidator()' => [DefaultValidatorRequest::class],
    'its own getValidatorInstance()' => [ValidatorInstanceRequest::class],
    'its own validateResolved()' => [OwnValidateResolvedRequest::class],
    'its own all()' => [OwnAllRequest::class],
    // The built validator is handed over, and `setData()` replaces what its rules run over.
    'withValidator() giving the validator other data' => [SetDataValidatorRequest::class],
    'withValidator() handing the validator on' => [HandedValidatorRequest::class],
    'no hook at all' => [FormRequest::class],
    'a class that is no FormRequest' => [stdClass::class],
]);

it('keeps a copy beside hooks that add checks to the validator without changing its data', function (): void {
    // `after()` callbacks, and a `->after()` added in `withValidator()`, run once the rules have been run,
    // and `sometimes()` adds a rule: none of them changes the input the rules validate.
    expect((new CopiedInputs)->of(copiedInputContext(), AfterHooksRequest::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('gives up a copy on an override of every framework method that decides what a FormRequest validates', function (): void {
    // Stated from the installed framework, not from the list: resolving a FormRequest runs
    // `validateResolved()` down to the one call that builds a validator from the request's data. Every
    // method on that path, every method the data argument is read through, and every method the framework
    // asks for by name in place of one on the path, decides what is validated — an application override of
    // any of them may validate something other than the merged input.
    $sources = [];
    foreach ([FormRequest::class, ValidatesWhenResolvedTrait::class] as $declaring) {
        $sources[] = (string) file_get_contents((string) (new ReflectionClass($declaring))->getFileName());
    }

    $finder = new NodeFinder;
    /** @var array<string, Node\Stmt\ClassMethod> $methods */
    $methods = [];
    foreach ($sources as $source) {
        $statements = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForHostVersion()->parse($source) ?? []);
        foreach ($finder->findInstanceOf($statements, Node\Stmt\ClassMethod::class) as $method) {
            $methods[$method->name->toString()] ??= $method; // the class's own over its trait's
        }
    }

    $thisCalls = static fn (Node|array $in): array => array_values(array_unique(array_map(
        static fn (Node\Expr\MethodCall $call): string => $call->name instanceof Node\Identifier ? $call->name->toString() : '',
        $finder->find($in, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'),
    )));
    $askedFor = static fn (Node $condition): ?string => $condition instanceof Node\Expr\FuncCall
        && $condition->name instanceof Node\Name && $condition->name->toString() === 'method_exists'
        && ($condition->getArgs()[1]->value ?? null) instanceof Node\Scalar\String_
        ? $condition->getArgs()[1]->value->value
        : null;

    // The sink: `make()` on the validation factory a method is handed.
    $sink = null;
    foreach ($methods as $name => $method) {
        foreach ($method->params as $param) {
            if ($param->type instanceof Node\Name && $param->type->toString() === 'Illuminate\\Contracts\\Validation\\Factory'
                && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $variable = $param->var->name;
                $sink = [$name, $finder->findFirst($method, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                    && $node->var instanceof Node\Expr\Variable && $node->var->name === $variable
                    && $node->name instanceof Node\Identifier && $node->name->toString() === 'make')];
            }
        }
    }
    expect($sink)->not->toBeNull()->and($sink[1])->toBeInstanceOf(Node\Expr\MethodCall::class);

    // The path: every method from validateResolved() that reaches the sink's method.
    $reaches = static function (string $from, string $to, array $seen = []) use (&$reaches, $methods, $thisCalls): bool {
        if ($from === $to) {
            return true;
        }
        if (isset($seen[$from]) || ! isset($methods[$from])) {
            return false;
        }
        foreach ($thisCalls($methods[$from]) as $callee) {
            if ($reaches($callee, $to, [...$seen, $from => true])) {
                return true;
            }
        }

        return false;
    };
    $path = array_values(array_filter(array_keys($methods), static fn (string $name): bool => $reaches('validateResolved', $name) && $reaches($name, $sink[0])));

    // The data: what the sink's first argument is read through, followed through the methods declared here.
    $data = [];
    $pending = $thisCalls($sink[1]->getArgs()[0]->value);
    while ($pending !== []) {
        $name = array_shift($pending);
        if (! isset($data[$name])) {
            $data[$name] = true;
            $pending = [...$pending, ...(isset($methods[$name]) ? $thisCalls($methods[$name]) : [])];
        }
    }

    // The stand-ins: `method_exists($this, 'x') ? x : <a step on the path>`.
    $standIns = [];
    foreach ($finder->find(array_values($methods), static fn (Node $node): bool => $node instanceof Node\Stmt\If_ || $node instanceof Node\Expr\Ternary) as $choice) {
        $asked = $askedFor($choice->cond);
        $otherwise = $choice instanceof Node\Stmt\If_ ? $choice->else : $choice->else;
        if ($asked !== null && $otherwise !== null && array_intersect($thisCalls($otherwise), $path) !== []) {
            $standIns[] = $asked;
        }
    }

    $expected = array_values(array_unique([...$path, ...array_keys($data), ...$standIns]));
    sort($expected, SORT_STRING);

    expect($path)->toContain('validateResolved')
        ->and($standIns)->not->toBeEmpty()
        ->and($expected)->toBe(CopiedInputs::FORM_REQUEST_DATA_METHODS);
});

/*
 * laravel-actions calls an action's `prepareForValidation()` through the container, which hands its
 * `ActionRequest` parameter the very request the package then validates — so a merge through that parameter
 * is the FormRequest's `$this->merge()`, and is read by the same rules.
 */
it("reads an action's copy through the request the package hands its hook, whatever the hook calls it", function (): void {
    // `X-Locale` again answers its default when absent, and a copy through another service is no copy.
    expect((new CopiedInputs)->ofAction(copiedInputContext(), CopyingAction::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
        'page' => ['in' => 'query', 'name' => 'page'],
        'post' => ['in' => 'path', 'name' => 'post'],
        'trace' => ['in' => 'header', 'name' => 'X-Trace-Id'],
    ]);
});

it('keeps an action copy beside a withValidator() that only adds checks', function (): void {
    expect((new CopiedInputs)->ofAction(copiedInputContext(), AfterHooksAction::class))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
    ]);
});

it('trusts no action copy where the hook may not be merging into what the package validates', function (?string $class): void {
    expect((new CopiedInputs)->ofAction(copiedInputContext(), $class))->toBe([]);
})->with([
    // The framework's request is not the package's: a form body is copied into the package's before this runs.
    "the framework's request" => [BaseRequestAction::class],
    'no request parameter' => [UnhandedAction::class],
    'a second handle on the input' => [TwoRequestsAction::class],
    'the same request under a second name' => [SameRequestTwiceAction::class],
    // The package validates what these hand it rather than the merged input.
    'its own getValidationData()' => [OwnValidationDataAction::class],
    'its own getValidator()' => [OwnValidatorAction::class],
    'withValidator() giving the validator other data' => [SetDataValidatorAction::class],
    'the request handed to its own code' => [HandsRequestOnAction::class],
    'its own code, handed nothing' => [OwnMethodAction::class],
    'a request it holds' => [HeldRequestAction::class],
    'the variable pointed elsewhere' => [ReassignedRequestAction::class],
    'the variable reached by a variable name' => [NamedRequestAction::class],
    'the variable handed on by its name' => [CompactedRequestAction::class],
    "the container's binding of the same request" => [ContainerActionRequestAction::class],
    'a hookless action' => [PublishArticleAction::class],
    'no action at all' => [null],
]);

/*
 * The framework's `validate()` on a request validates `$this->all()` as it stands at the call, and so does a
 * `Validator::make()` handed `$request->all()`: what the statements before it merged is what is validated.
 */
it('reads a copy merged before an inline validation in the action body', function (string $method, array $expected): void {
    expect((new CopiedInputs)->ofInline(inlineCopyContext($method), 1))->toBe($expected);
})->with([
    'validate()' => ['store', [
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key'],
        'page' => ['in' => 'query', 'name' => 'page'],
    ]],
    'Validator::make() of all()' => ['factory', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    'an assigned validate()' => ['assigned', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    // The action's own attribute sits above its keyword, where the parser starts the method and reflection does not.
    'an attributed action' => ['attributed', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    // Running the validator made of `all()` validates that same input, and `validated()` runs it too.
    'Validator::make() of all(), validated on the spot' => ['chained', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    'the same, as a statement' => ['chainedStatement', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    'Validator::make() of all(), then validated()' => ['chainedValidated', ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]],
    'Validator::make() of other data, validated on the spot' => ['chainedOtherData', []],
    // `setData()` points the validator at other input before it runs.
    'Validator::make() of all(), given other data' => ['dataReplaced', []],
    // After the validation the merge changes nothing it validated.
    'merged after it' => ['mergedAfter', []],
    // A validation in a branch may not run, and one of other data never reads the merge.
    'a validation in a branch' => ['branched', []],
    'Validator::make() of other data' => ['otherData', []],
    'its own code called first' => ['helperFirst', []],
    // A FormRequest parameter was validated before the body ran; its own inline call is not what this reads.
    'a FormRequest parameter' => ['formRequest', []],
]);

it('reads no inline copy where the rules were harvested from more validations than the one it can see', function (): void {
    // With two, a key the body copies may take its rules from the other one, which the merge may not precede.
    expect((new CopiedInputs)->ofInline(inlineCopyContext('store'), 2))->toBe([])
        ->and((new CopiedInputs)->ofInline(inlineCopyContext('store'), 0))->toBe([]);
});

it('reads an inherited hook in the class that declares it, and keys the route on that file', function (): void {
    $context = copiedInputContext();

    expect((new CopiedInputs)->of($context, InheritedCopiesRequest::class))->toBe(['key' => ['in' => 'header', 'name' => 'Idempotency-Key']])
        ->and($context->dependencyFiles())->toContain((string) (new ReflectionClass(BaseCopiesRequest::class))->getFileName());
});

it('reads a hook a trait supplies in the trait, under the name the trait gives it, and keys the route on that file', function (string $class, array $expected, string $trait): void {
    $context = copiedInputContext();
    $copied = is_a($class, FormRequest::class, true) ? (new CopiedInputs)->of($context, $class) : (new CopiedInputs)->ofAction($context, $class);

    // PHP reports a trait's method as the using class's, but its body is written in the trait.
    expect($copied)->toBe($expected)
        ->and($context->dependencyFiles())->toContain((string) (new ReflectionClass($trait))->getFileName());
})->with([
    'a FormRequest' => [TraitHookRequest::class, ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']], CopiesIdempotencyKey::class],
    // `copyTrace as prepareForValidation`: the framework runs the aliased method's body.
    'a FormRequest, under an alias' => [AliasedTraitHookRequest::class, ['trace' => ['in' => 'header', 'name' => 'X-Trace-Id']], CopiesTraceId::class],
    // `insteadof` picks which trait's body the framework runs.
    'a FormRequest, one of two' => [ChosenTraitHookRequest::class, ['locale' => ['in' => 'header', 'name' => 'X-Locale']], CopiesLocale::class],
    'an action' => [TraitHookAction::class, ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']], CopiesKeyInAction::class],
    // An attribute above the trait's keyword, however the class takes the method.
    'a FormRequest, attributed' => [AttributedTraitHookRequest::class, ['region' => ['in' => 'header', 'name' => 'X-Region']], CopiesRegion::class],
    'a FormRequest, attributed, under an alias' => [AttributedAliasedTraitHookRequest::class, ['tenant' => ['in' => 'header', 'name' => 'X-Tenant']], CopiesTenant::class],
    'a FormRequest, attributed, the second of two' => [AttributedChosenTraitHookRequest::class, ['region' => ['in' => 'header', 'name' => 'X-Region']], CopiesRegion::class],
    // Both traits in one file under one name: only the line reflection reports tells their bodies apart.
    'a FormRequest, one of two traits written in one file' => [SameFileChosenHookRequest::class, ['device' => ['in' => 'header', 'name' => 'X-Device']], CopiesDeviceHere::class],
    'an action, attributed' => [AttributedTraitHookAction::class, ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']], CopiesKeyAttributedInAction::class],
]);

/*
 * Reflection places a method on its `function` keyword and the parser on its first attribute or modifier, so
 * a hook or an action with either on a line of its own is where the two disagree. Pint joins a split modifier
 * back onto the keyword's line, so those bodies are written to a file of their own.
 */
it('reads a hook with an attribute on the line above its keyword', function (string $class): void {
    $copied = is_a($class, FormRequest::class, true)
        ? (new CopiedInputs)->of(copiedInputContext(), $class)
        : (new CopiedInputs)->ofAction(copiedInputContext(), $class);

    expect($copied)->toBe(['key' => ['in' => 'header', 'name' => 'Idempotency-Key']]);
})->with([
    'a FormRequest' => [AttributedHookRequest::class],
    'an action' => [AttributedHookAction::class],
]);

it('reads a hook, an aliased trait method and an inline action with their visibility on a line of their own', function (): void {
    $namespace = copiedInputsSplitModifiers();
    $inline = new RouteContext(
        route: new RouteDescriptor(['POST'], '/api/notes'),
        actionRef: new ActionRef('', $namespace.'\\SplitController', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
    $key = ['key' => ['in' => 'header', 'name' => 'Idempotency-Key']];

    expect((new CopiedInputs)->of(copiedInputContext(), $namespace.'\\SplitRequest'))->toBe($key)
        ->and((new CopiedInputs)->of(copiedInputContext(), $namespace.'\\SplitAliasedRequest'))->toBe($key)
        ->and((new CopiedInputs)->ofAction(copiedInputContext(), $namespace.'\\SplitAction'))->toBe($key)
        ->and((new CopiedInputs)->ofInline($inline, 1))->toBe($key);
});

it('moves a copied key only where the rules name it as one plain value', function (): void {
    $rules = new RuleSet([
        'key' => [ValidationRule::of('required'), ValidationRule::of('uuid')],
        'tags' => [ValidationRule::of('array')],
        'meta' => [ValidationRule::of('required')],
        'meta.id' => [ValidationRule::of('integer')],
        'note' => [ValidationRule::of('string')],
    ]);
    $header = static fn (string $name): array => ['in' => 'header', 'name' => $name];

    // `tags` is a container and `meta` has a member: neither is one header or query value, so both stay.
    // `unvalidated` has no rules to move, and `note` is not copied at all.
    expect(CopiedInputs::movable($rules, [
        'key' => $header('Idempotency-Key'),
        'meta' => $header('X-Meta'),
        'tags' => $header('X-Tags'),
        'unvalidated' => $header('X-Unvalidated'),
    ]))->toBe([
        'key' => ['in' => 'header', 'name' => 'Idempotency-Key', 'rules' => $rules->fields['key']],
    ]);
});

it('publishes on a parameter exactly the rules the body gave up, and nothing it did not', function (): void {
    $context = copiedInputContext();
    $rules = new RuleSet([
        'key' => [ValidationRule::of('required'), ValidationRule::of('uuid')],
        'page' => [ValidationRule::of('nullable'), ValidationRule::of('integer')],
        'title' => [ValidationRule::of('required'), ValidationRule::of('string')],
    ]);

    // Nothing left the body, so a parameter has nothing to carry — whatever the FormRequest copies.
    $untouched = new OperationDraft;
    $untouched->parameter('header', 'idempotency-key');
    (new CopiedInputParameters)->handle($untouched, $context);
    expect($untouched->parameterKeys())->toBe(['header:idempotency-key'])
        ->and($untouched->parameter('header', 'idempotency-key')->schema()->saysNothingAboutTheInstance())->toBeTrue()
        ->and($untouched->parameter('header', 'idempotency-key')->resolvedField('required'))->toBeNull();

    $operation = new OperationDraft;
    $operation->parameter('header', 'idempotency-key');
    $kept = (new CopiedInputs)->move($operation, (new CopiedInputs)->of($context, CertainCopiesRequest::class), $rules);
    (new CopiedInputParameters)->handle($operation, $context);

    // The body keeps what the client sends; the header, found under the case the read published it by,
    // and the query value carry the rules that left it (their schemas are the feature test's to pin).
    expect(array_keys($kept->fields))->toBe(['title'])
        ->and(array_keys(CopiedInputs::movedFrom($operation)))->toBe(['key', 'page'])
        ->and($operation->parameterKeys())->toBe(['header:idempotency-key', 'query:page'])
        ->and($operation->parameter('header', 'idempotency-key')->resolvedField('required'))->toBeTrue()
        ->and($operation->parameter('header', 'idempotency-key')->schema()->resolvedField('type'))->toBe('string')
        ->and($operation->parameter('query', 'page')->resolvedField('required'))->toBeFalse();
});

it('moves a key a partition is read off with the rules it has where no partition is proved', function (string $class, string $key): void {
    $rules = new RuleSet(array_map(RuleParsing::tokens(...), [
        'channel' => 'required|in:email,sms',
        'address' => 'exclude_unless:channel,email|required|email',
        'phone' => 'exclude_unless:channel,sms|required|string|max:20',
        'body' => 'required|string|max:500',
    ]));
    $normalizer = new RuleSetNormalizer;
    $plain = $normalizer->normalize($rules);
    $operation = new OperationDraft;

    $kept = (new CopiedInputs)->move($operation, (new CopiedInputs)->of(copiedInputContext(), $class), $normalizer->normalize($rules, true));

    // The split had moved the tag's `required` and the member's gated `required` onto the partition; the
    // parameter publishes what the server validates the copied value by, and the body is given up whole.
    expect(CopiedInputs::movedFrom($operation)[$key]['rules'])->toEqual($plain->fields[$key])
        ->and($kept->variants)->toBe([])
        ->and($kept->fields)->toEqual(array_diff_key($plain->fields, [$key => true]));
})->with([
    'the tag' => [StoreRoutedNoticeRequest::class, 'channel'],
    'a member the tag switches' => [StoreDialledNoticeRequest::class, 'phone'],
]);

/*
 * "Required" is exactly "the validator fails a present null", since a copied key holds null when what it
 * copies was not sent. So every rule asked is checked against the application's own validator, spelled the
 * way an application writes it, alone and beside `nullable`; one this version does not have is refused
 * there, and adds no claim here.
 */
it('names a rule as refusing a null exactly where the installed validator fails one', function (string|object $rule): void {
    $name = is_string($rule) ? explode(':', $rule)[0] : 'enum';
    $parsed = is_string($rule)
        ? ValidationRule::of($name, str_contains($rule, ':') ? str_getcsv(explode(':', $rule, 2)[1], escape: '\\') : [])
        : ValidationRule::of('enum', array_map(static fn (WidgetStatus $case): string => $case->value, WidgetStatus::cases()), WidgetStatus::class);
    $validatorFails = static function (array $rules): bool {
        try {
            return Validator::make(['k' => null], ['k' => $rules])->fails();
        } catch (BadMethodCallException) {
            return false;
        }
    };

    expect(NullRejection::rejects([$parsed]))->toBe($validatorFails([$rule]))
        ->and(NullRejection::rejects([ValidationRule::of('nullable'), $parsed]))->toBe($validatorFails(['nullable', $rule]));
})->with(function (): array {
    $samples = [
        'array_keys' => 'array_keys:a', 'between' => 'between:0,4', 'contains' => 'contains:a', 'date_format' => 'date_format:Y-m-d',
        'decimal' => 'decimal:0,2', 'digits' => 'digits:3', 'digits_between' => 'digits_between:0,4', 'dimensions' => 'dimensions:min_width=1',
        'doesnt_contain' => 'doesnt_contain:a', 'doesnt_end_with' => 'doesnt_end_with:x', 'doesnt_start_with' => 'doesnt_start_with:x',
        'encoding' => 'encoding:UTF-8', 'ends_with' => 'ends_with:x', 'extensions' => 'extensions:png', 'in' => 'in:web,mobile',
        'in_array_keys' => 'in_array_keys:a', 'max' => 'max:36', 'max_digits' => 'max_digits:3', 'mimes' => 'mimes:png',
        'mimetypes' => 'mimetypes:image/png', 'min' => 'min:0', 'min_digits' => 'min_digits:0', 'multiple_of' => 'multiple_of:2',
        'not_in' => 'not_in:web,mobile', 'not_regex' => 'not_regex:/^a$/', 'regex' => 'regex:/^a$/',
        'required_array_keys' => 'required_array_keys:a', 'size' => 'size:0', 'starts_with' => 'starts_with:x',
    ];

    $rows = ['enum' => [Rule::enum(WidgetStatus::class)]];
    foreach (NullRejection::ASKED as $name) {
        $rows[$name] = [$samples[$name] ?? $name];
    }

    return $rows;
});

/*
 * The same answers stated from the contract rather than asked for, so the check above cannot pass by the two
 * sides agreeing on a wrong spelling. Laravel 13 type-checks the value before `ascii` and the digit and
 * prefix rules, where 12 hands a null on and lets it through; `base64` is a rule 13 added.
 */
it('names a rule set as refusing a null exactly where the installed Laravel does', function (string $rules, bool $on12, bool $on13): void {
    $parsed = array_map(static function (string $rule): ValidationRule {
        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);

        return ValidationRule::of($name, $parameters === null ? [] : explode(',', $parameters));
    }, explode('|', $rules));

    expect(NullRejection::rejects($parsed))->toBe((int) explode('.', Application::VERSION)[0] >= 13 ? $on13 : $on12);
})->with([
    'nullable' => ['nullable|uuid', false, false],
    'a bound alone' => ['max:36', false, false],
    'present' => ['present', false, false],
    'a form rule' => ['uuid', true, true],
    'a condition that does not hold, with nullable' => ['required_if:other,yes|nullable|string', false, false],
    'a condition beside a form rule' => ['required_if:other,yes|string', true, true],
    'an empty string among the choices' => ['in:,web', false, false],
    'ascii' => ['ascii', false, true],
    'no digits' => ['digits_between:0,4', false, true],
    'a digit bound' => ['max_digits:3', false, true],
    'a refused prefix' => ['doesnt_start_with:x', false, true],
    'a refused suffix' => ['doesnt_end_with:x', false, true],
    'a rule only 13 has' => ['base64', false, true],
    // Never asked: it needs a database, and a build may not query one.
    'a store' => ['exists:users,id', false, false],
]);

it('adds no claim for a rule it cannot ask, and one the validator refuses leaves the others standing', function (): void {
    expect(NullRejection::rejects([ValidationRule::of('object')]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('email', ['dns'])]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('enum', ['a'], 'NotAnEnum')]))->toBeFalse()
        // `digits_between` needs two parameters, so the validator refuses it, and `required` still answers.
        ->and(NullRejection::rejects([ValidationRule::of('digits_between')]))->toBeFalse()
        ->and(NullRejection::rejects([ValidationRule::of('digits_between'), ValidationRule::of('required')]))->toBeTrue();
});

it('classes every rule the installed validator has as asked or not, and says why for each it does not ask', function (): void {
    $rules = [];
    foreach ((new ReflectionClass(ValidatorInstance::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (preg_match('/^validate[A-Z]/', $method->name) === 1) {
            $rules[] = Str::snake(substr($method->name, 8));
        }
    }

    expect(count($rules))->toBeGreaterThan(100)
        ->and(array_values(array_diff($rules, NullRejection::ASKED, array_keys(NullRejection::NOT_ASKED))))->toBe([])
        ->and(array_values(array_intersect(NullRejection::ASKED, array_keys(NullRejection::NOT_ASKED))))->toBe([]);
});

it('publishes a part optional where the rules only exclude it on a condition', function (): void {
    // Whether `exclude_if` drops the key is read at runtime, so neither answer is certain and the wider
    // one — optional — is what cannot mark a working request invalid.
    expect(NullRejection::rejects([ValidationRule::of('exclude_if', ['mode', 'draft']), ValidationRule::of('required')]))->toBeFalse();
});
