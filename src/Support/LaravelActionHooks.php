<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\WithAttributes;

/**
 * The one reading of `lorisleiva/laravel-actions`: whether a class is an action, whether the package
 * validates for a dispatch, and which of the action's methods it runs by name doing so. The package is named
 * by `::class`, which loads nothing, so this is inert without it; every list is checked against the
 * installed package's source.
 */
final class LaravelActionHooks
{
    /** The trait that makes a class an action served as a controller; `AsAction` uses it. */
    public const CONTROLLER_TRAIT = AsController::class;

    /** The trait that opts an action out of the package's automatic request validation. */
    public const WITH_ATTRIBUTES_TRAIT = WithAttributes::class;

    /** The request the package validates with, which it resolves each hook's parameters against. */
    public const ACTION_REQUEST = ActionRequest::class;

    /** The methods the package treats as non-explicit (it remaps invokable routes onto these). */
    private const DISPATCH_METHODS = ['asController', 'handle', '__invoke'];

    /** The methods whose presence makes the package validate at all: `ControllerDecorator::hasAnyValidationMethod()`. */
    public const VALIDATION_METHODS = ['afterValidator', 'authorize', 'getValidator', 'rules', 'withValidator'];

    /** Every method of the action the package's request calls by name while it validates. */
    public const HOOKS = [
        'afterValidator', 'authorize', 'getAuthorizationFailure', 'getValidationAttributes', 'getValidationData',
        'getValidationErrorBag', 'getValidationFailure', 'getValidationMessages', 'getValidationRedirect',
        'getValidator', 'prepareForValidation', 'rules', 'withValidator',
    ];

    /**
     * The hooks the package validates with in place of its default input or its default validator, so what
     * the validator reads is not the request's merged input.
     */
    public const VALIDATION_OVERRIDES = ['getValidationData', 'getValidator'];

    public static function isAction(string $fqcn): bool
    {
        return trait_exists(self::CONTROLLER_TRAIT) && self::usesTrait($fqcn, self::CONTROLLER_TRAIT);
    }

    /**
     * Mirrors the rest of `ControllerDecorator::shouldValidateRequest()`: the package only validates for a
     * non-explicit dispatched method (so an explicitly-registered `[Action::class, 'store']` never does) on
     * an action without `WithAttributes`.
     */
    public static function dispatchesValidation(string $fqcn, string $method): bool
    {
        return self::isAction($fqcn)
            && in_array($method, self::DISPATCH_METHODS, true)
            && ! self::usesTrait($fqcn, self::WITH_ATTRIBUTES_TRAIT);
    }

    /** Whether the package validates at all for this dispatch, and so runs the action's {@see HOOKS}. */
    public static function runsHooks(string $fqcn, string $method): bool
    {
        if (! self::dispatchesValidation($fqcn, $method)) {
            return false;
        }

        foreach (self::VALIDATION_METHODS as $validation) {
            if (method_exists($fqcn, $validation)) {
                return true;
            }
        }

        return false;
    }

    /** Own traits, parents' and traits used by traits, so `AsAction` (which uses `AsController`) counts. */
    private static function usesTrait(string $fqcn, string $trait): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        $traits = [];
        foreach (array_merge([$fqcn], class_parents($fqcn) ?: []) as $class) {
            self::collectTraits($class, $traits);
        }

        return isset($traits[$trait]);
    }

    /**
     * @param  array<string, string>  $acc
     */
    private static function collectTraits(string $class, array &$acc): void
    {
        foreach (class_uses($class) ?: [] as $trait) {
            if (! isset($acc[$trait])) {
                $acc[$trait] = $trait;
                self::collectTraits($trait, $acc);
            }
        }
    }
}
