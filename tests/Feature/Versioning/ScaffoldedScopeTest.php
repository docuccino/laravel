<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Support\JsonValue;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `docuccino:version-changes` and `#[AppliesTo]`: when the scaffold narrows a change, when it must not,
 * and what the build derives from each.
 *
 * The direction is the whole thing, and it is the one an author would get backwards. A shared component
 * has ONE shape, so a component that changed changed for every operation still publishing it — scoping
 * such a change would fork the operations it named and leave the rest at today's shape, which is the
 * document-wide rewrite the scope exists to prevent, in reverse. A scope is owed only where the
 * application itself forked: an operation that published today's shape in the older version already,
 * because it pointed somewhere else then.
 *
 * Both branches round-trip here rather than being asserted from the written file alone. A scaffold whose
 * output the product consumes differently from the way it was meant is worth less than no scaffold.
 */
beforeEach(function (): void {
    // A directory and a namespace of its own per test. `require_once` is keyed on the FILE PATH and a
    // class is declared once per process, so two tests scaffolding `FormDataTitleReplacesName` into one
    // path would have the second read the first one's attributes back out of memory — or, once the
    // namespaces differed, declare nothing at all and derive from no change whatsoever. Both look like
    // a passing test that asserts something else.
    $sequence = partialScopeSequence();

    $this->root = rtrim(sys_get_temp_dir(), '/').'/docuccino-scope-scaffold-'.getmypid().'-'.$sequence;
    $this->changes = $this->root.'/changes';

    $prefix = 'Docuccino\\ScopeScaffolded'.$sequence.'\\';

    partialScopeReset($this->root);
    mkdir($this->changes, 0755, true);
    file_put_contents(
        $this->root.'/composer.json',
        (string) json_encode(['autoload' => ['psr-4' => [$prefix => 'changes/']]]),
    );

    app()->setBasePath($this->root);
    bindStubEngine();

    $changes = $this->changes;
    spl_autoload_register(static function (string $class) use ($changes, $prefix): void {
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $file = $changes.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/archived', [VersionedFormController::class, 'archived']);

    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Forms API', 'version' => '2026-09-01'],
        'routes' => ['include' => ['api/versioned-forms*']],
        'error_responses' => 'none',
        'api_version' => ['changes' => ['changes']],
    ]]);
});

afterEach(function (): void {
    partialScopeReset($this->root);
});

/** A number nothing else in this process has used, so each test's classes are its own. */
function partialScopeSequence(): int
{
    static $sequence = 0;

    return ++$sequence;
}

/** Everything under a directory, and the directory, gone. */
function partialScopeReset(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

/**
 * The head document as an array — two operations, one `FormData`, both pointing at it.
 *
 * @return array<string, mixed>
 */
function partialScopeHead(): array
{
    return generateDocument(key: 'v')->document->toArray();
}

/**
 * `FormData` as the 2026-06-01 version published it: `title` was called `name`. Written as a mutation of
 * the current component so its identity is the one this build mints, which is what the diff pairs on.
 *
 * @return array<string, mixed>
 */
function partialScopeOldFormData(): array
{
    /** @var array<string, mixed> $current */
    $current = partialScopeHead()['components']['schemas']['FormData'];

    $properties = [];
    foreach (JsonValue::decode((string) json_encode($current['properties'])) as $name => $property) {
        $properties[$name === 'title' ? 'name' : (string) $name] = $property;
    }

    $current['properties'] = $properties;
    $current['required'] = ['id', 'name'];

    return $current;
}

/**
 * The previous version's artifact on disk. `$fork` writes the modular case: the archived operation had a
 * component of its OWN back then, carrying exactly the shape the head now publishes for everybody —
 * which is the application forking, and the only thing that owes an `#[AppliesTo]`.
 */
function partialScopeArtifact(bool $fork, bool $inline = false): string
{
    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((new UirEmitter)->emit(generateDocument(key: 'v')->document));

    /** @var array<string, mixed> $head */
    $head = partialScopeHead()['components']['schemas']['FormData'];

    $uir['components']['schemas']['FormData'] = partialScopeOldFormData();
    $uir['info'] = ['title' => 'Forms API', 'version' => '2026-06-01'];

    if ($fork) {
        // A second component, its own identity, the shape the head publishes today.
        $head['x-docuccino'] = ['id' => 'sch:v1:archivedform00001'];
        $uir['components']['schemas']['ArchivedFormData'] = $head;
        $uir['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items']
            = ['$ref' => '#/components/schemas/ArchivedFormData'];
    }

    if ($inline) {
        // A copy of its own carrying the OLDER shape — what a document derived under a scope looks like.
        // The archived operation reaches no shared component here, and what it does publish is not
        // today's shape either, so nothing can vouch for its type having stayed put.
        $own = partialScopeOldFormData();
        $own['x-docuccino'] = ['id' => 'sch:v1:archivedfork00001'];
        $uir['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'] = $own;
    }

    $path = rtrim(sys_get_temp_dir(), '/').'/docuccino-scope-published-'.getmypid().'.uir.json';
    file_put_contents($path, (string) json_encode($uir));

    return $path;
}

/**
 * A node with every identity dropped. A fork mints an id of its own — two nodes cannot answer to one —
 * so identity is the one thing a derived shape cannot be compared on.
 *
 * @param  array<array-key, mixed>  $node
 * @return array<array-key, mixed>
 */
function partialScopeShape(array $node): array
{
    unset($node['x-docuccino']);

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = partialScopeShape($value);
        }
    }

    return $node;
}

/**
 * The schema one operation of the derived document publishes for a form, `$ref` followed.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function partialScopePublished(array $document, string $path): array
{
    /** @var array<string, mixed> $items */
    $items = $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']['items'];

    if (isset($items['$ref'])) {
        $name = substr((string) $items['$ref'], strlen('#/components/schemas/'));

        /** @var array<string, mixed> $items */
        $items = $document['components']['schemas'][$name];
    }

    return $items;
}

it('narrows the change to the operations that really changed, and to nothing else', function (): void {
    $old = partialScopeArtifact(fork: true);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        // Reported as well as written: a narrowed change is the one thing a reader would not expect.
        ->expectsOutputToContain("#[AppliesTo(operation: 'GET /api/versioned-forms')]")
        ->assertSuccessful();

    $written = (string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php');

    expect($written)->toContain("#[AppliesTo(operation: 'GET /api/versioned-forms')]")
        // Named one operation at a time: a `*` covering both would silently take the archived one with
        // it, which is the widening the whole computation exists to avoid.
        ->and($written)->not->toContain('archived')
        ->and($written)->toContain('use Docuccino\\Attributes\\Versioning\\AppliesTo;');

    @unlink($old);
});

it('derives the older document from what it wrote, in scope and out of scope both', function (): void {
    // The round trip. The application forked in 2026-06-01: the archived operation had its own component
    // then, already carrying today's shape. So deriving that version has to give the list operation the
    // older shape and leave the archived one alone — and the shared component stays where the head put
    // it, because the operations out of scope still publish it.
    $old = partialScopeArtifact(fork: true);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    config()->set('docuccino.documents.v.info.version', '2026-06-01');
    $derived = partialScopeHead();

    /** @var array<string, mixed> $published */
    $published = JsonValue::decode((string) file_get_contents($old));

    expect(partialScopeShape(partialScopePublished($derived, '/api/versioned-forms')))
        ->toBe(partialScopeShape($published['components']['schemas']['FormData']))
        ->and(partialScopeShape(partialScopePublished($derived, '/api/versioned-forms/archived')))
        ->toBe(partialScopeShape($published['components']['schemas']['ArchivedFormData']))
        // And the out-of-scope operation keeps the SHARED component rather than a copy of its own.
        ->and($derived['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData']);

    @unlink($old);
});

it('emits no scope when the change covers every operation that publishes the schema', function (): void {
    // The direction, executed. Both operations published `FormData` in 2026-06-01, so both changed by
    // construction — and an `#[AppliesTo]` naming one of them would fork that one and leave the other at
    // the shape the code publishes today, which is a document that lies about the version.
    $old = partialScopeArtifact(fork: false);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))
        // The verb first, so a class that was never written could not pass this by containing nothing.
        ->toContain("#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]")
        ->not->toContain('AppliesTo');

    config()->set('docuccino.documents.v.info.version', '2026-06-01');
    $derived = partialScopeHead();

    // No fork, so the component itself carries the older shape and both operations still point at it —
    // byte for byte the document the older version published, identities included.
    expect($derived['components']['schemas']['FormData'])->toBe(partialScopeOldFormData())
        ->and($derived['paths']['/api/versioned-forms']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($derived['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData']);

    @unlink($old);
});

it('widens rather than narrows for an operation whose old shape it cannot vouch for', function (): void {
    // The positive-evidence half, and the one a scope-by-identity-alone rule gets wrong. The archived
    // operation reaches no shared component in the old document — it carried a copy of its own — so on
    // identity alone it would drop out of the scope and keep today's shape. What it published back then
    // was the OLDER shape, so it has to be rewritten too, and the change stays document-wide.
    $old = partialScopeArtifact(fork: false, inline: true);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))
        ->toContain("#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]")
        ->not->toContain('AppliesTo');

    config()->set('docuccino.documents.v.info.version', '2026-06-01');
    $derived = partialScopeHead();

    // Both operations get the older shape, which is what both of them published.
    expect(partialScopeShape(partialScopePublished($derived, '/api/versioned-forms')))
        ->toBe(partialScopeShape(partialScopeOldFormData()))
        ->and(partialScopeShape(partialScopePublished($derived, '/api/versioned-forms/archived')))
        ->toBe(partialScopeShape(partialScopeOldFormData()));

    @unlink($old);
});
