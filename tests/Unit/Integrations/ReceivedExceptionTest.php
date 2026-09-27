<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\InferredHandler\ReceivedException;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\SelfRenderingMissingModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Exceptions\PaymentRequiredException;

/**
 * {@see ReceivedException} held to the installed framework twice over: each row against what the real
 * handler hands its callbacks at run time, and the table against every arm `prepareException()` declares,
 * so a conversion added upstream fails here instead of narrowing a callback to a class it never sees.
 */

/**
 * What the booted handler hands a render callback and the `respond()` callback when it renders `$thrown`.
 *
 * @return array{render: class-string|null, respond: class-string|null}
 */
function receivedAtRunTime(Throwable $thrown): array
{
    $seen = ['render' => null, 'respond' => null];

    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(static function (Throwable $e) use (&$seen): null {
        $seen['render'] = $e::class;

        return null;
    });
    $handler->respondUsing(static function (Response $response, Throwable $e) use (&$seen): Response {
        $seen['respond'] = $e::class;

        return $response;
    });

    $handler->render(Request::create('/api/probe', server: ['HTTP_ACCEPT' => 'application/json']), $thrown);

    return $seen;
}

/** A throw of each table row's base, built the way the framework throws it. */
function preparedRowInstance(string $base): ?Throwable
{
    return match ($base) {
        BackedEnumCaseNotFoundException::class => new BackedEnumCaseNotFoundException('App\\Enums\\Status', 'missing'),
        ModelNotFoundException::class => new ModelNotFoundException,
        AuthorizationException::class => new AuthorizationException,
        'Illuminate\\Http\\Exceptions\\OriginMismatchException' => class_exists($base) ? new $base : null,
        TokenMismatchException::class => new TokenMismatchException,
        'Symfony\\Component\\HttpFoundation\\Exception\\RequestExceptionInterface' => new SuspiciousOperationException,
        RecordNotFoundException::class => new RecordNotFoundException,
        RecordsNotFoundException::class => new RecordsNotFoundException,
        default => throw new LogicException("No throw of {$base} to check its row against: add one here."),
    };
}

it('names the class every callback is handed for each conversion prepareException() makes', function (string $base, string $prepared): void {
    $thrown = preparedRowInstance($base);
    if ($thrown === null) {
        expect(class_exists($base))->toBeFalse();

        return; // A conversion this framework version does not have: nothing to throw.
    }

    $seen = receivedAtRunTime($thrown);

    expect($seen['render'])->toBe($prepared)
        ->and($seen['respond'])->toBe($prepared)
        ->and(ReceivedException::byRenderCallbacks($thrown::class))->toBe($prepared)
        ->and(ReceivedException::byRespondCallback($thrown::class))->toBe($prepared);
})->with(static function (): array {
    $rows = [];
    foreach (ReceivedException::PREPARED as $base => $prepared) {
        $rows[$base] = [$base, $prepared];
    }

    return $rows;
});

it('hands any other throw to the callbacks as it was thrown', function (): void {
    $seen = receivedAtRunTime(new RuntimeException('boom'));

    expect($seen)->toBe(['render' => RuntimeException::class, 'respond' => RuntimeException::class])
        ->and(ReceivedException::byRenderCallbacks(RuntimeException::class))->toBe(RuntimeException::class)
        ->and(ReceivedException::byRespondCallback(RuntimeException::class))->toBe(RuntimeException::class);
});

it('hands respond() the throw as it was where the exception renders itself, and asks no render callback', function (): void {
    $seen = receivedAtRunTime(new PaymentRequiredException);

    expect($seen)->toBe(['render' => null, 'respond' => PaymentRequiredException::class])
        ->and(ReceivedException::byRespondCallback(PaymentRequiredException::class))->toBe(PaymentRequiredException::class);
});

it('does not name what respond() is handed where that turns on what the exception’s own render() returns', function (): void {
    $answers = new SelfRenderingMissingModel;
    $answers->renders = true;
    $declines = new SelfRenderingMissingModel;

    // Both happen, for one thrown class: the throw as it is where render() answers, prepared where not.
    expect(receivedAtRunTime($answers)['respond'])->toBe(SelfRenderingMissingModel::class)
        ->and(receivedAtRunTime($declines)['respond'])->toBe('Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException')
        ->and(ReceivedException::byRespondCallback(SelfRenderingMissingModel::class))->toBeNull();
});

it('holds a row for every conversion the installed prepareException() makes, in its order', function (): void {
    $method = new ReflectionMethod(Handler::class, 'prepareException');
    $node = ParsedClassFile::methodsOf((string) $method->getFileName(), Handler::class)['prepareException'] ?? null;
    expect($node)->not->toBeNull();

    $match = (new NodeFinder)->findFirstInstanceOf($node?->stmts ?? [], Expr\Match_::class);
    expect($match)->toBeInstanceOf(Expr\Match_::class);

    /** @var array<string, list<array{target: string, conditional: bool}>> $arms */
    $arms = [];
    foreach ($match?->arms ?? [] as $arm) {
        foreach ($arm->conds ?? [] as $cond) {
            $instanceof = (new NodeFinder)->findFirstInstanceOf([$cond], Expr\Instanceof_::class);
            if ($instanceof === null || ! $instanceof->class instanceof Name || ! $arm->body instanceof Expr\New_ || ! $arm->body->class instanceof Name) {
                continue;
            }

            $arms[$instanceof->class->toString()][] = [
                'target' => $arm->body->class->toString(),
                // `instanceof X && ! $e->hasStatus()`: the arm read when the thrown value states nothing more.
                'conditional' => ! $cond instanceof Expr\Instanceof_ && (new NodeFinder)->findFirstInstanceOf([$cond], Expr\BooleanNot::class) === null,
            ];
        }
    }

    // A scan that saw nothing would pass everything below; 12.0 already converted seven classes.
    expect(count($arms))->toBeGreaterThanOrEqual(7);

    foreach ($arms as $base => $targets) {
        $unconditional = array_values(array_filter($targets, static fn (array $t): bool => ! $t['conditional']));
        expect(ReceivedException::PREPARED)->toHaveKey($base)
            ->and($unconditional)->toHaveCount(1)
            ->and(ReceivedException::PREPARED[$base] ?? null)->toBe($unconditional[0]['target'] ?? null);
    }

    // Matched first-wins, so the rows the framework declares keep its order; a row it does not declare is
    // a class this version does not have.
    $installed = array_values(array_filter(array_keys(ReceivedException::PREPARED), static fn (string $base): bool => isset($arms[$base])));
    expect($installed)->toBe(array_keys($arms));
    foreach (array_diff(array_keys(ReceivedException::PREPARED), $installed) as $absent) {
        expect(class_exists($absent) || interface_exists($absent))->toBeFalse();
    }
});
