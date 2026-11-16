<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use ReflectionClass;

/**
 * What a route's FormRequest answers a request it refuses with, where the application overrides the hook the
 * framework refuses through: `failedValidation()` for a validation that fails, `failedAuthorization()` for a
 * gate that denies. The framework calls the hook before the action runs, so whatever it throws answers the
 * request in place of the framework's own error: a response it carries is sent as it is, and an exception is
 * rendered as any other. Read on the request's class or any parent the application declares it on.
 */
final readonly class FormRequestFailure
{
    public const VALIDATION = 'failedValidation';

    public const AUTHORIZATION = 'failedAuthorization';

    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    /**
     * @param  list<ReturnSite>  $responses  the responses the hook throws for the framework to send as they are
     * @param  list<ThrownException>  $throws  the errors it throws for the framework to render
     */
    private function __construct(
        public string $hook,
        public string $label,
        public array $responses,
        public array $throws,
    ) {}

    /**
     * The override of `$hook` the route's FormRequest runs, or null where the framework's own runs — no
     * FormRequest, or none of its classes overriding the hook.
     */
    public static function of(RouteContext $context, string $hook): ?self
    {
        $formRequest = $context->formRequestClass;
        if ($formRequest === null || ! class_exists($formRequest)) {
            return null;
        }

        // Recorded before the bail: an override added to the request, a base it extends or a trait it uses
        // has to rebuild a warm-cached route that had none.
        $context->recordDependencyFiles(DeclarationFiles::of($formRequest));

        $reflection = new ReflectionClass($formRequest);
        if (! $reflection->hasMethod($hook)) {
            return null;
        }

        $method = $reflection->getMethod($hook);
        $declaring = $method->getDeclaringClass()->getName();
        $file = $method->getFileName();
        if ($declaring === self::FORM_REQUEST || $file === false) {
            return null;
        }

        // Analysed where it is written, which is the only place its body exists.
        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef($file, $declaring, $hook, $line === false ? 0 : $line));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        // A hook returns nothing the framework reads, so a return is only ever a response it throws to be sent;
        // one of no type the engine knows is a body it failed to read, not a response.
        $responses = array_values(array_filter(
            $analysis->returns,
            static fn (ReturnSite $site): bool => ! $site->type instanceof VoidT && ! $site->type instanceof NeverT && ! $site->type instanceof UnknownT,
        ));
        $throws = array_values(array_filter(
            $analysis->throws,
            static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal,
        ));

        return new self($hook, $declaring.'::'.$hook.'()', $responses, $throws);
    }

    /** Whether the build read anything the hook answers with. One it did not is no evidence the hook sends nothing. */
    public function isRead(): bool
    {
        return $this->responses !== [] || $this->throws !== [];
    }
}
