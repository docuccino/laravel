<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Extensions\ErrorResponsesExtension;
use Docuccino\Laravel\Extensions\ImplicitResponsesExtension;
use Docuccino\Laravel\Integrations\LaravelActions\ActionAuthorizeResponsesExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponsesExtension;
use Docuccino\Laravel\Support\IgnoredResponses;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * `error_responses => 'none'` is a statement about a whole document, and five separate producers have to
 * honour it: the declared errors read off the analysis, the implicit 401/403/404/422 synthesized from
 * middleware and bindings, the Query Builder's strict 400, an action's `authorize()` 403, and the
 * rate-limit 429. Each has its own row where it is implemented, and each row is a SUBSET guard — the
 * one that shipped without a gate had a passing row of its own asserting the body it published.
 *
 * So the guard here is the union, and it is asked of the DOCUMENT rather than of any producer: build the
 * whole workbench route set with the switch off and nothing anywhere may publish a 4xx or a 5xx. The
 * paired build with it on is the floor — without it this would pass just as well over a document that
 * never had an error response to lose, which is exactly how a producer stays outside a guard.
 *
 * @return list<string>
 */
function errorStatuses(array $document): array
{
    $statuses = [];

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'] ?? [];
    foreach ($paths as $item) {
        foreach ($item as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            foreach (array_keys(is_array($operation['responses'] ?? null) ? $operation['responses'] : []) as $status) {
                if (preg_match('/^[45]\d\d$/', (string) $status) === 1) {
                    $statuses[] = (string) $status;
                }
            }
        }
    }

    $statuses = array_values(array_unique($statuses));
    sort($statuses);

    return $statuses;
}

/** Every producer of an error response reached at once: a throttled route beside the workbench's own set. */
function errorSwitchDocument(string $errorResponses): array
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/switch-throttled', [FormController::class, 'index'])->middleware('throttle:60,1');

    // A throw the analysis recovered, so the tier that reads DECLARED errors contributes here too and the
    // union really is all five producers rather than the four that need no engine.
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(throws: [
            new ThrownException('App\\Exceptions\\SwitchProbe', 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ]),
    ]));

    return generateDocument(static function (array $raw) use ($errorResponses): array {
        $raw['error_responses'] = $errorResponses;

        return $raw;
    })->document->toArray();
}

it('publishes no 4xx or 5xx anywhere when the document documents no errors', function (): void {
    expect(errorStatuses(errorSwitchDocument('none')))->toBe([]);
});

it('publishes them all with the switch on, which is what makes the row above mean something', function (): void {
    // The floor, and it names the statuses rather than counting them: each is a different producer, so a
    // producer that stopped contributing shows up here as a missing status instead of as a number that
    // still clears a threshold. The 429 is the rate-limit integration's, the 422 the validated request's,
    // the 404 the route binding's, the 401 the auth middleware's, and the 403 an `authorize()` gate's.
    // The 409 is a throw the analysis recovered, which is the fifth producer.
    expect(errorStatuses(errorSwitchDocument('default')))
        ->toContain('401')
        ->toContain('403')
        ->toContain('404')
        ->toContain('422')
        ->toContain('429')
        ->toContain('409');
});

it('gates every class that reaches the error-response chain, whatever a build happens to reach', function (): void {
    // The document rows above are executed proof, and they only prove the producers an in-process build
    // reaches: the Query Builder's strict 400 needs a real QB trace and an action's `authorize()` 403
    // needs an action route, so neither is in that document. A guard silent outside what a fixture
    // happens to exercise is how the 429 shipped ungated with a passing row of its own, so the POPULATION
    // is derived instead — every class in the adapter that reaches the error-response chain, by either of
    // the two ways there are of reaching it — and each has to read the switch.
    //
    // The set is asserted as a LIST rather than against a floor. It is five, so no number "well under
    // what the tree holds" is far enough above zero to mean anything; naming them fails on a scan that
    // lost a member, on a scanner that found none, and on a sixth producer arriving — which is the case
    // this exists for, and it fails whether or not the newcomer is gated, so the decision gets made.
    $gated = [];
    $ungated = [];

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($entries as $entry) {
        if (! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($entry->getPathname());
        // The two ways to reach the chain: through the ignore reader, which every tiered producer uses,
        // and directly, which the middleware-synthesized 429 has to.
        if (! str_contains($source, 'IgnoredResponses::mapThrow(') && ! str_contains($source, '$context->mapThrow(')) {
            continue;
        }
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
            || preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            continue;
        }

        $fqcn = trim($namespace[1]).'\\'.$class[1];

        if (str_contains($source, "errorResponses === 'none'")) {
            $gated[] = $fqcn;
        } else {
            $ungated[] = $fqcn;
        }
    }

    sort($gated);
    sort($ungated);

    expect($gated)->toBe([
        ErrorResponsesExtension::class,
        ImplicitResponsesExtension::class,
        ActionAuthorizeResponsesExtension::class,
        QueryBuilderParametersExtension::class,
        RateLimitResponsesExtension::class,
    ])
        // The one member owing no gate, and it carries a row rather than falling in the gap: the ignore
        // reader is what the producers reach the chain THROUGH, not a producer. It publishes nothing of
        // its own, so there is nothing for the switch to withhold.
        ->and($ungated)->toBe([IgnoredResponses::class]);
});
