<?php

declare(strict_types=1);

use Docuccino\Core\Support\Glob;
use Illuminate\Support\Str;

/**
 * `routes.include`/`routes.exclude` were read by `Str::is` and are now read by {@see Glob}, so core can
 * read the same wildcard an adapter config has always used. That is only true while the two agree, and
 * a guard that asked `Glob` for its own rule would agree with whatever `Glob` did — so this asks
 * Laravel, which is where the rule came from.
 */
it('reads a wildcard exactly as the framework helper it replaced does', function (string $pattern, string $subject): void {
    expect(Glob::matches($pattern, $subject))->toBe(Str::is($pattern, $subject));
})->with(function (): iterable {
    $patterns = ['api/*', 'api/forms', '*', 'api/*/forms', '*forms', 'api/forms*', 'api.forms', 'a+', 'api/form?', 'api/[fg]orms', '', 'GET /api/users/*'];
    $subjects = ['api/forms', 'api/forms/1', 'api/deeply/nested', 'apiXforms', 'aaa', 'forms', '', 'GET /api/users/9/invoices', 'api/[fg]orms'];

    foreach ($patterns as $pattern) {
        foreach ($subjects as $subject) {
            yield sprintf('"%s" against "%s"', $pattern, $subject) => [$pattern, $subject];
        }
    }
});
