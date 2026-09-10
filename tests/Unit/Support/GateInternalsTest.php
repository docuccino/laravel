<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\GateInternals;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Lightbox;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Totem;
use Docuccino\Laravel\Tests\Fixtures\Authorization\TotemAccess;
use Illuminate\Auth\Access\Gate as IlluminateGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A Gate carrying no registrations at all, so a resolution here can only have come from the model:
 * every row below is about a branch that reads the class rather than the map.
 */
function gateWithNoRegistrations(): GateContract
{
    return new IlluminateGate(app(), static fn () => null);
}

/**
 * The mirror's branch list held against the framework's OWN resolution, read out of
 * `Gate::getPolicyFor()` rather than out of a list copied from it. Every branch of that method ends in
 * `return $this->resolvePolicy(...)`, so counting those sites counts the branches — and the count is a
 * function of the version the application resolved: 12 walks four, 13 walks five.
 *
 * This is the row that fails when the framework grows a sixth. Without it a mirror gone short again is
 * silence for the models the new branch answers for, which is the safe direction and still a gate that
 * resolves in the application and not in the document.
 */
it('walks every branch the installed framework resolves a policy through', function (): void {
    $method = new ReflectionMethod(IlluminateGate::class, 'getPolicyFor');
    $file = $method->getFileName();
    expect($file)->toBeString();

    $lines = is_string($file) ? file($file) : false;
    expect($lines)->toBeArray();

    $start = (int) $method->getStartLine();
    $source = implode('', array_slice(is_array($lines) ? $lines : [], $start - 1, (int) $method->getEndLine() - $start + 1));

    // Counted off the parsed method rather than one spelling of the call: a branch that resolves
    // through a nullsafe hop, over a fluent line break, or into a local before returning is the same
    // branch, and a pattern blind to it leaves the mirror agreeing with a short count.
    $framework = phpMethodCallCount("<?php\n\nclass GatePolicyProbe\n{\n".$source."\n}\n", 'resolvePolicy');

    // A scan that stopped recognising its shapes must fail rather than pass: the exact map, the
    // `#[UsePolicy]` attribute, the conventional name and a registration on a parent type are four
    // branches the framework has walked for the whole of the attribute's life.
    expect($framework)->toBeGreaterThanOrEqual(4);

    $internals = GateInternals::read(gateWithNoRegistrations());
    expect($internals)->not->toBeNull();

    // The mirror's own count, taken from the list it resolves through rather than from a number written
    // beside it — a hand-kept total would agree with whatever it said.
    $branches = (new ReflectionMethod(GateInternals::class, 'resolutionBranches'))->invoke($internals);

    expect($branches)->toBeArray()
        ->and(count(is_array($branches) ? $branches : []))->toBe($framework);
});

/**
 * How the fifth branch is decided. `class_exists()` says nothing about a version, and neither does
 * `method_exists()` — both majors publish `getPolicyFromAttribute()` — so what is read is the
 * signature the framework shipped, and this row holds that reading against the framework's own
 * BEHAVIOUR rather than against the way the reading was computed.
 */
it('reads the parent-attribute branch off the installed grammar, not off a version', function (): void {
    $gate = gateWithNoRegistrations();
    $internals = GateInternals::read($gate);

    expect($internals)->not->toBeNull()
        ->and($internals?->parentAttributes)->toBe($gate->getPolicyFor(Lightbox::class) !== null);
});

it('answers a parent-inherited #[UsePolicy] wherever the installed framework does', function (): void {
    $gate = gateWithNoRegistrations();
    $internals = GateInternals::read($gate);
    expect($internals)->not->toBeNull();

    // The premise, stated before the comparison: every branch ahead of the parent-attribute one really
    // does miss. Nothing is registered, the model carries no attribute of its own, and no conventional
    // name it would be looked up under exists — so a resolution here can only be the parent's.
    $existing = array_filter(
        $internals?->guessedNames(Lightbox::class) ?? [],
        static fn (string $name): bool => class_exists($name),
    );

    expect($internals?->policies)->toBe([])
        ->and((new ReflectionClass(Lightbox::class))->getAttributes(UsePolicy::class))->toBe([])
        ->and($existing)->toBe([]);

    $framework = $gate->getPolicyFor(Lightbox::class);

    expect($internals?->policyClassFor(Lightbox::class))
        ->toBe(is_object($framework) ? $framework::class : null);

    // Anti-vacuity for the version where that answer is null: the attribute is readable and the
    // resolution does reach it one class up, on every version — so a null above is the framework
    // declining to walk parents and never a fixture nothing answers for.
    expect($internals?->policyClassFor(Totem::class))->toBe(TotemAccess::class);
});

it('reaches a parent-inherited policy without building one', function (): void {
    TotemAccess::$constructed = 0;
    $gate = gateWithNoRegistrations();
    $internals = GateInternals::read($gate);

    // Both attribute branches, and neither may construct: the accessor the Gate contract publishes for
    // this ends in `$container->make($policy)`, and the whole of GateInternals exists to answer with a
    // class name instead.
    $internals?->policyClassFor(Totem::class);
    $internals?->policyClassFor(Lightbox::class);

    expect(TotemAccess::$constructed)->toBe(0);

    // Anti-vacuity: the counter does move, and what moves it is the very accessor the resolution is
    // written not to use.
    $gate->getPolicyFor(Totem::class);

    expect(TotemAccess::$constructed)->toBe(1);
});
