<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Illuminate\Contracts\Support\Responsable;

/**
 * The exception a handler callback is actually handed for a throw. `Handler::render()` converts the throw
 * through `prepareException()` before it tries a render callback or passes the result to `respond()`, so a
 * callback typed for the thrown class may never see it (design §6).
 */
final class ReceivedException
{
    private const NOT_FOUND = 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException';

    private const HTTP = 'Symfony\\Component\\HttpKernel\\Exception\\HttpException';

    /**
     * `prepareException()`'s conversions, in its match order: thrown base class → the class it becomes. An
     * `AuthorizationException` becomes an `HttpException` only where the denial carries a status of its own,
     * which no tier reads (it is classified 403 throughout), so it is read as the plain denial it is otherwise.
     * Held to the installed framework by `ReceivedExceptionTest`.
     */
    public const PREPARED = [
        'Illuminate\\Routing\\Exceptions\\BackedEnumCaseNotFoundException' => self::NOT_FOUND,
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => self::NOT_FOUND,
        'Illuminate\\Auth\\Access\\AuthorizationException' => 'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException',
        'Illuminate\\Http\\Exceptions\\OriginMismatchException' => self::HTTP,
        'Illuminate\\Session\\TokenMismatchException' => self::HTTP,
        'Symfony\\Component\\HttpFoundation\\Exception\\RequestExceptionInterface' => 'Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException',
        'Illuminate\\Database\\RecordNotFoundException' => self::NOT_FOUND,
        'Illuminate\\Database\\RecordsNotFoundException' => self::NOT_FOUND,
    ];

    /** What a render callback is handed for a throw of `$thrown`. */
    public static function byRenderCallbacks(string $thrown): string
    {
        foreach (self::PREPARED as $base => $prepared) {
            if ($thrown === $base || is_a($thrown, $base, true)) {
                return $prepared;
            }
        }

        return $thrown;
    }

    /**
     * What the `respond()` callback is handed for a throw of `$thrown`, or null where that turns on what the
     * exception's own `render()` returns at run time: the throw as it is where that answers, prepared where
     * it does not.
     */
    public static function byRespondCallback(string $thrown): ?string
    {
        $prepared = self::byRenderCallbacks($thrown);
        if ($prepared === $thrown || is_a($thrown, Responsable::class, true)) {
            return $thrown;
        }

        return method_exists($thrown, 'render') ? null : $prepared;
    }
}
