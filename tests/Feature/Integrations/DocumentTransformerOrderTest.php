<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralSummaryTransformer;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallbackSkipTransformer;
use Docuccino\Laravel\Integrations\Sanctum\SanctumCookieReport;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * A lint reads the document as it will be emitted, so every one of them runs after anything that can
 * still change it — today only `SharedErrorResponses`, which hoists a repeated error body into a shared
 * component. The pin is `Priorities::LAST` rather than an edge onto that one class, so a transformer
 * registered later lands ahead of the lints whatever it is called. Resolved through the real
 * {@see ExtensionRegistry}, so neither registration order nor an FQCN settles it.
 *
 * The tail is measured against `shippedLints()` rather than a list written here: a hand-kept list pins
 * only the lints it names, and a lint added outside it sat unasserted with the suite green.
 */

/** @return list<string> */
$order = static function (): array {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(new DocumentConfig('default', [])), []);

    return array_map(static fn (object $transformer): string => $transformer::class, $resolved->documentTransformers);
};

it('runs the document lints last, whatever else is registered', function () use ($order): void {
    $lints = shippedLints();

    // `shippedLints()` is sorted and the resolved tail is FQCN-sorted within the priority, so the two
    // lists match member for member — and the count is the slice, so neither can go stale.
    expect(array_slice($order(), -count($lints)))->toBe($lints);
});

it('runs the one transformer that changes bytes before them, and the FQCN tie-break does not', function () use ($order): void {
    $before = array_slice($order(), 0, -count(shippedLints()));

    // Docuccino\Laravel\… sorts after Docuccino\Core\Lint\…, so on the priority tie these three ran
    // last and the pin is what moves them: an FQCN is not an ordering contract.
    expect($before)->toContain(
        SharedErrorResponses::class,
        HandlerDeferralSummaryTransformer::class,
        RenderCallbackSkipTransformer::class,
        SanctumCookieReport::class,
    );
});
