<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Integrations\Validation\UnreadRule;
use Docuccino\Laravel\Integrations\Validation\UnreadRuleNotices;

/**
 * Annotating a rule class with `#[RuleSchema]` answers every field it is on, so the notices one class raises
 * are one remedy. On a 284-operation application one class was 29 of its 46 unread-rule notices, each
 * printing the same help; what a reader needs is the class, once, and where it reached.
 */
it('reads the notices one rule class raises as one, naming the operations it reached', function (): void {
    $source = new Source('app/Rules/Translatable.php', null, 'App\\Rules\\Translatable');
    $unread = static fn (string $route, ?Source $source, string $help = 'Annotate it.'): Diagnostic => new Diagnostic(
        severity: Severity::Info,
        code: 'validation.rule-unrecoverable',
        message: 'A field on '.$route.' has a rule this build cannot read.',
        source: $source,
        routeSignature: $route,
        help: $help,
    );

    $grouped = UnreadRuleNotices::grouped([
        $unread('PUT /groups/{group}', $source),
        $unread('POST /groups', $source),
        $unread('POST /groups', $source),
        // A closure, or a field with several unread rules, names no class: nothing to group it with.
        $unread('POST /forms', null),
        // A class raising a notice once is already one notice.
        $unread('POST /tags', new Source('app/Rules/Slug.php', null, 'App\\Rules\\Slug')),
        new Diagnostic(Severity::Info, 'validation.rule-values-unread', 'Another code.', $source, 'POST /groups'),
    ]);

    $merged = array_values(array_filter($grouped, static fn (Diagnostic $d): bool => $d->routeSignature === null));

    expect($grouped)->toHaveCount(4)
        ->and($merged)->toHaveCount(1)
        ->and($merged[0]->message)->toBe('App\\Rules\\Translatable is a rule this build cannot read, so it is left off each of the 3 fields it validates, across 2 operations (POST /groups, PUT /groups/{group}); the rest of their rules are documented.')
        ->and($merged[0]->source)->toBe($source)
        ->and($merged[0]->help)->toBe('Annotate it.')
        ->and(array_map(static fn (Diagnostic $d): ?string => $d->routeSignature, $grouped))->toContain('POST /forms', 'POST /tags', 'POST /groups');
});

it('reads the notices of every order alike', function (): void {
    $source = new Source('app/Rules/Translatable.php', null, 'App\\Rules\\Translatable');
    $notices = array_map(static fn (string $route): Diagnostic => new Diagnostic(Severity::Info, 'validation.rule-unrecoverable', 'm', $source, $route, 'h'), ['GET /a', 'GET /b', 'GET /c']);

    expect(UnreadRuleNotices::grouped(array_reverse($notices))[0]->message)->toBe(UnreadRuleNotices::grouped($notices)[0]->message);
});

/** The class a label names is read back exactly as {@see UnreadRule::classify()} wrote it, and nothing else is. */
it('reads the class back out of the label it wrote, and out of nothing else', function (string $label, ?string $class): void {
    expect(UnreadRule::classOf($label))->toBe($class);
})->with([
    'a rule object' => [UnreadRule::classify('App\\Rules\\Translatable')['label'], 'App\\Rules\\Translatable'],
    'a leading backslash' => [UnreadRule::classify('\\App\\Rules\\Translatable')['label'], 'App\\Rules\\Translatable'],
    'a closure' => [UnreadRule::classify('Closure')['label'], null],
    'a descriptor' => ['Rule::when(...)', null],
    'nothing after new' => ['new ', null],
]);
