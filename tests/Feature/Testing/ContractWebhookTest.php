<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\WebhookPayload;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Data\WidgetData;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Webhooks\FormSubmitted;

/*
 * The outbound half of contract testing, against the workbench's own `#[Webhook]` classes: the payload
 * an application dispatches, held to the body the generated document publishes for that name.
 */

afterEach(function (): void {
    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
    ApiContract::reset();
});

it('passes the payload class the webhook is documented from', function (): void {
    workbenchWebhookContract();

    // The delivered object itself — the shape `#[Webhook]` published, dispatched as the code holds it.
    // No `expect()` here on purpose: a passing check registers itself as the assertion it is, so a test
    // whose only check is this one is not reported as having performed none.
    ApiContract::assertions()->assertValidWebhook('form.submitted', new FormSubmitted(7, '2026-01-01T00:00:00Z'));
});

it('passes a payload named by the attribute rather than by the annotated class', function (): void {
    workbenchWebhookContract();

    // `#[Webhook('widget.archived', payload: WidgetData::class)]` — a `put`, and the only method that
    // name is published under, so nothing has to say so.
    ApiContract::assertions()->assertValidWebhook('widget.archived', new WidgetData(1, 'Sprocket', WidgetStatus::Archived));
});

it('takes the payload as an array, as JSON text and as the object, all the same way', function (mixed $payload): void {
    workbenchWebhookContract();

    ApiContract::assertions()->assertValidWebhook('form.submitted', $payload);
})->with([
    'the object' => [new FormSubmitted(7, '2026-01-01T00:00:00Z')],
    'an array' => [['formId' => 7, 'submittedAt' => '2026-01-01T00:00:00Z']],
    'JSON text' => ['{"formId":7,"submittedAt":"2026-01-01T00:00:00Z"}'],
]);

it('fails a payload whose shape disagrees, naming the webhook and the schema node', function (): void {
    workbenchWebhookContract();

    try {
        ApiContract::assertions()->assertValidWebhook('form.submitted', ['formId' => 'seven', 'submittedAt' => 'now']);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('The payload dispatched for POST webhooks.form.submitted does not match the documented contract.')
            ->toContain('webhook    POST webhooks.form.submitted  op:v1:')
            ->toContain('the delivered payload at /formId')
            ->toContain('must match the type: integer')
            ->toContain('schema   /components/schemas/FormSubmitted/properties/formId');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('fails a payload missing a member the contract requires', function (): void {
    workbenchWebhookContract();

    expect(fn () => ApiContract::assertions()->assertValidWebhook('form.submitted', ['formId' => 7]))
        ->toThrow(AssertionFailedError::class, 'The required properties (submittedAt) are missing');
});

/**
 * The enum is the whole point of holding an outbound payload to its schema: a sender that starts
 * delivering a value the published contract does not list breaks every consumer that generated a type
 * from it, and no HTTP test in the suite goes anywhere near it.
 */
it('fails a payload carrying a value outside the enum the document publishes', function (): void {
    workbenchWebhookContract();

    expect(fn () => ApiContract::assertions()->assertValidWebhook('widget.archived', [
        'id' => 1,
        'name' => 'Sprocket',
        'status' => 'retired',
    ]))->toThrow(AssertionFailedError::class, 'schema   /components/schemas/WidgetStatus');
});

it('fails a name the document does not publish, naming the webhooks it does', function (): void {
    workbenchWebhookContract();

    try {
        ApiContract::assertions()->assertValidWebhook('invoice.paid', ['id' => 1]);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('webhooks.invoice.paid is not documented.')
            ->toContain('The contract documents these webhooks:')
            ->toContain('    form.submitted')
            ->toContain('    widget.archived')
            // #[ExcludeFromDocs] kept it out of the document, so it is not a name to offer either.
            ->not->toContain('legacy.ping')
            ->toContain('php artisan docuccino:export');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('fails a method the name is not published under, naming the one it is', function (): void {
    workbenchWebhookContract();

    expect(fn () => ApiContract::assertions()->assertValidWebhook('widget.archived', ['id' => 1], method: 'post'))
        ->toThrow(AssertionFailedError::class, 'The contract publishes that webhook, for these methods:');
});

it('checks the method asked for when the name is published under just the one', function (): void {
    workbenchWebhookContract();

    // Case-insensitively: a caller writes the method the way the attribute does.
    ApiContract::assertions()->assertValidWebhook('widget.archived', new WidgetData(1, 'Sprocket', WidgetStatus::Draft), method: 'PUT');
});

it('refuses to guess between the methods one name is published under', function (): void {
    // Two classes may claim one name for different methods, and then which body a payload answers to is
    // the caller's to say — guessing would check it against a body it was never sent as.
    workbenchContract(static function (array $raw): array {
        app()->setBasePath(dirname(__DIR__, 3));
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Contested'];

        return $raw;
    });

    try {
        ApiContract::assertions()->assertValidWebhook('shipment.updated', ['reference' => 'SHP-1']);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('webhooks.shipment.updated is documented for more than one method')
            ->toContain('POST webhooks.shipment.updated')
            ->toContain('PUT webhooks.shipment.updated')
            ->toContain("Name the one you send: assertValidWebhook('shipment.updated', \$payload, method: '");

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

/*
 * The outbound half of the coverage report. A delivery is not an exchange — there is no operation and no
 * TestResponse — so it reaches the recorder by a road of its own, and lands in the report as the one
 * thing a sending application's suite can prove about a webhook.
 */
it('counts a webhook it asserted, and the ones the suite never delivered', function (): void {
    workbenchWebhookContract();

    ApiContract::assertions()->assertValidWebhook('form.submitted', new FormSubmitted(7, '2026-01-01T00:00:00Z'));

    $report = ApiContract::report();
    $deliveries = [];
    foreach ($report->rows as $row) {
        if (str_contains($row->label, 'webhooks.')) {
            $deliveries[$row->label] = $row->responses[0]->status.'='.($row->responses[0]->exercised ? 'y' : 'n');
        }
    }

    // Two webhooks documented, one delivered. Before this the report counted neither, so `--min=100`
    // called the outbound half complete having measured none of it.
    expect($deliveries)->toBe([
        'POST webhooks.form.submitted' => 'delivery=y',
        'PUT webhooks.widget.archived' => 'delivery=n',
    ])->and($report->complete())->toBeFalse()
        ->and($report->render(100.0))->toContain('PUT webhooks.widget.archived');
});

it('writes a delivery to the coverage log a bootstrap asked for, like any other entry', function (): void {
    $directory = coverageFixtureDir('webhook');

    try {
        workbenchWebhookContract();
        ApiContract::recordCoverage($directory);

        ApiContract::assertions()->assertValidWebhook('form.submitted', new FormSubmitted(7, '2026-01-01T00:00:00Z'));

        $exercised = ApiContract::coverage()->exercised();

        // No status: a webhook's statuses are what the RECEIVER answers, and nothing a sender's suite
        // does can exercise one — recording a `200` here would credit a promise nobody kept.
        expect($exercised)->toHaveCount(1)
            ->and($exercised[0])->not->toContain('@')
            ->and(file_get_contents((string) ApiContract::coverage()->logPath()))->toBe($exercised[0].'
');
    } finally {
        removeCoverageFixture($directory);
    }
});

/*
 * A pass that could not read what the document published says so, on the channel a developer running the
 * suite actually sees. A note nobody is told is how a suite comes to believe it has contract coverage it
 * does not have — and the docs promise these notes exist.
 */
it('warns the developer that a delivery passed having proved less than it looks like', function (): void {
    // A real build of a real `#[Webhook(mediaType: 'text/csv')]`, not a document written to suit this:
    // the producer publishes the media type the attribute names, and JSON Schema cannot check that body.
    workbenchContract(static function (array $raw): array {
        app()->setBasePath(dirname(__DIR__, 3));
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Uncheckable'];

        return $raw;
    });

    $warnings = warningsRaisedBy(static function (): void {
        ApiContract::assertions()->assertValidWebhook('report.ready', ['reference' => 'RPT-1']);
    });

    expect($warnings)->toBe([
        'POST webhooks.report.ready passed, but part of the contract was not checked: '.
        'the delivered payload is text/csv, which JSON Schema cannot check.',
    ]);
});

it('says nothing at all about a delivery it checked in full', function (): void {
    workbenchWebhookContract();

    expect(warningsRaisedBy(static function (): void {
        ApiContract::assertions()->assertValidWebhook('form.submitted', new FormSubmitted(7, '2026-01-01T00:00:00Z'));
    }))->toBe([]);
});

/*
 * A webhook the document publishes no body for is a test-authoring error, not a checker limitation:
 * nothing is in the way, the document simply says nothing, and a green assertion would claim a payload
 * had been held to something. Docuccino's own producer always writes a body, so this fires only on a
 * document a hand or an overlay wrote — which is exactly why the artifact here is edited rather than
 * generated.
 */
it('fails a webhook the document publishes no delivered body for at all', function (): void {
    $path = workbenchWebhookContract();
    $document = json_decode((string) file_get_contents($path), true);

    expect($document['webhooks']['form.submitted']['post']['requestBody'] ?? null)->toBeArray();

    unset($document['webhooks']['form.submitted']['post']['requestBody']);
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    try {
        ApiContract::assertions()->assertValidWebhook('form.submitted', new FormSubmitted(7, '2026-01-01T00:00:00Z'));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('The payload dispatched for POST webhooks.form.submitted does not match the documented contract.')
            ->toContain('POST webhooks.form.submitted')
            ->toContain('documents no delivered body, so there is nothing here for a payload to be held to');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('shows a forged webhook name its own escape sequences rather than obeying them', function (): void {
    // The one line of the new assertions that interpolates without escaping first. A name is the
    // document's string as much as the caller's — a hand-written or imported artifact can publish
    // anything under `webhooks`, and the hint prints it straight into a terminal.
    $forgery = "\x1b[32mAll contract assertions passed";
    $path = workbenchContract(static function (array $raw): array {
        app()->setBasePath(dirname(__DIR__, 3));
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Contested'];

        return $raw;
    });

    $document = json_decode((string) file_get_contents($path), true);
    $document['webhooks']['shipment.updated'.$forgery] = $document['webhooks']['shipment.updated'];
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    try {
        ApiContract::assertions()->assertValidWebhook('shipment.updated'.$forgery, ['reference' => 'SHP-1']);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain("assertValidWebhook('shipment.updated\\x1B[32mAll contract assertions passed', \$payload")
            ->not->toContain($forgery);

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('says a 3.0 artifact cannot carry webhooks rather than that the webhook is undocumented', function (): void {
    workbenchWebhookContract();

    // The same build, downlevelled: 3.0 defines no `webhooks` member, so every one of them was dropped.
    $path = sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json';
    file_put_contents($path, (new OpenApi30DownlevelEmitter)->emit(generateDocument(static function (array $raw): array {
        $raw['webhooks'] = ['dir' => 'workbench/app/Webhooks'];

        return $raw;
    })->document));
    ApiContract::using($path);

    try {
        ApiContract::assertions()->assertValidWebhook('form.submitted', ['formId' => 7]);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('which defines no `webhooks` member')
            ->toContain('Assert against the UIR artifact, or a 3.1 or 3.2 export.')
            ->not->toContain('is not documented');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('fails a payload it cannot read as JSON, naming the webhook it was dispatched for', function (): void {
    workbenchWebhookContract();

    expect(fn () => ApiContract::assertions()->assertValidWebhook('form.submitted', ['handle' => fopen('php://memory', 'r')]))
        ->toThrow(
            AssertionFailedError::class,
            'Docuccino cannot read the payload dispatched for POST webhooks.form.submitted as JSON',
        );
});

it('fails JSON text that is not JSON at all', function (): void {
    workbenchWebhookContract();

    expect(fn () => ApiContract::assertions()->assertValidWebhook('form.submitted', '{"formId":'))
        ->toThrow(AssertionFailedError::class, 'the delivered payload is not valid JSON');
});

/*
 * The payload table. Every form an application can hold a payload in at the moment of dispatch, plus
 * the value that cannot be encoded at all.
 */
it('reduces every form of payload to the bytes the receiver would see', function (mixed $payload, string $json): void {
    expect(WebhookPayload::json($payload))->toBe($json);
})->with([
    'JSON text, verbatim' => ['{"id":1}', '{"id":1}'],
    'an array' => [['id' => 1], '{"id":1}'],
    'a list' => [[1, 2], '[1,2]'],
    'a plain object' => [(object) ['id' => 1], '{"id":1}'],
    'a payload class' => [new FormSubmitted(1, 'now'), '{"formId":1,"submittedAt":"now"}'],
    'a backed enum' => [WidgetStatus::Draft, '"draft"'],
    'null' => [null, 'null'],
    'a slash, unescaped, as it goes on the wire' => [['url' => 'a/b'], '{"url":"a/b"}'],
    'unicode, unescaped' => [['name' => 'café'], '{"name":"café"}'],
]);

it('asks an object what its own JSON is before it encodes one for it', function (mixed $payload, string $json): void {
    expect(WebhookPayload::json($payload))->toBe($json);
})->with([
    // Jsonable first: an object that states its JSON outranks every derivation of one.
    'Jsonable' => [new class implements Jsonable
    {
        public function toJson($options = 0): string
        {
            return '{"from":"toJson"}';
        }
    }, '{"from":"toJson"}'],
    // Then JsonSerializable, which outranks Arrayable where an object implements both.
    'JsonSerializable over Arrayable' => [new class implements Arrayable, JsonSerializable
    {
        public function toArray(): array
        {
            return ['from' => 'toArray'];
        }

        public function jsonSerialize(): array
        {
            return ['from' => 'jsonSerialize'];
        }
    }, '{"from":"jsonSerialize"}'],
    'Arrayable' => [new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['from' => 'toArray'];
        }
    }, '{"from":"toArray"}'],
]);

it('refuses a payload no encoder can turn into bytes', function (): void {
    expect(fn (): string => WebhookPayload::json(['handle' => fopen('php://memory', 'r')]))
        ->toThrow(JsonException::class);
});
