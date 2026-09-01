<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Support\JsonValue;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `docuccino:version-changes`, end to end.
 *
 * The test that matters is the ROUND TRIP: a genuine two-version diff is scaffolded into classes, and
 * the build then reads those classes back and derives the older document from them. A scaffold whose
 * output the product cannot consume is worthless, so the assertion is that the derived `FormData` is
 * the `FormData` the older version published — the artifact the command was handed, byte for byte.
 *
 * Everything runs against a temporary application root with a `composer.json` of its own, because the
 * command derives the namespace of what it writes from the PSR-4 map: a class the autoloader cannot
 * find is a change that never applies, so the derivation is part of what is under test rather than
 * scenery.
 */
beforeEach(function (): void {
    $this->root = rtrim(sys_get_temp_dir(), '/').'/docuccino-scaffold-'.getmypid();
    $this->changes = $this->root.'/changes';

    scaffoldTreeReset($this->root);
    mkdir($this->changes, 0755, true);
    file_put_contents(
        $this->root.'/composer.json',
        (string) json_encode(['autoload' => ['psr-4' => ['Docuccino\\Scaffolded\\' => 'changes/']]]),
    );

    app()->setBasePath($this->root);
    bindStubEngine();

    // The written classes have to LOAD for the round trip, so the temporary prefix gets an autoloader
    // of its own — the same job the application's composer autoloader does for a real one.
    $changes = $this->changes;
    spl_autoload_register(static function (string $class) use ($changes): void {
        if (! str_starts_with($class, 'Docuccino\\Scaffolded\\')) {
            return;
        }

        $file = $changes.'/'.str_replace('\\', '/', substr($class, strlen('Docuccino\\Scaffolded\\'))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);

    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Forms API', 'version' => '2026-09-01'],
        'routes' => ['include' => ['api/versioned-forms']],
        'error_responses' => 'none',
        'api_version' => ['changes' => ['changes']],
    ]]);
});

afterEach(function (): void {
    scaffoldTreeReset($this->root);
});

/** Everything under a directory, and the directory, gone. */
function scaffoldTreeReset(string $directory): void
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
 * The `FormData` the 2026-06-01 version published: `title` was called `name`, `subtotal` was still
 * there, and `id` was not promised. Written as a mutation of the CURRENT document so every identity in
 * it is the one this build mints — which is what the diff pairs on, exactly as a committed artifact
 * from a previous release would.
 *
 * @return array<string, mixed>
 */
function publishedFormData(): array
{
    return [
        'x-docuccino' => currentFormData()['x-docuccino'],
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'subtotal' => ['type' => 'integer', 'description' => 'The form total before tax, in cents.'],
            'publishedAt' => ['type' => ['string', 'null']],
        ],
        'required' => ['name'],
    ];
}

/**
 * `FormData` as the head document publishes it.
 *
 * @return array<string, mixed>
 */
function currentFormData(): array
{
    /** @var array<string, mixed> $schema */
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    return $schema;
}

/** The previous version's artifact, on disk, as UIR — the shape the command is pointed at. */
function publishedArtifact(): string
{
    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((new UirEmitter)->emit(generateDocument(key: 'v')->document));

    $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
    $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
    $schemas['FormData'] = publishedFormData();
    $components['schemas'] = $schemas;
    $uir['components'] = $components;
    $uir['info'] = ['title' => 'Forms API', 'version' => '2026-06-01'];

    $path = rtrim(sys_get_temp_dir(), '/').'/docuccino-published-'.getmypid().'.uir.json';
    file_put_contents($path, (string) json_encode($uir));

    return $path;
}

/** The classes the changes directory holds, sorted. */
function scaffoldedFiles(string $directory): array
{
    $files = array_map('basename', glob($directory.'/*.php') ?: []);
    sort($files, SORT_STRING);

    return $files;
}

it('scaffolds one class per difference the vocabulary expresses, and names where it wrote them', function (): void {
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('"v" at 2026-09-01, from the packaged stub.')
        ->expectsOutputToContain('into changes — the only configured change directory.')
        ->expectsOutputToContain('FormDataIdBecameRequired')
        ->expectsOutputToContain('FormDataLostSubtotal')
        ->expectsOutputToContain('FormDataTitleReplacesName')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->changes))->toBe([
        'FormDataIdBecameRequired.php',
        'FormDataLostSubtotal.php',
        'FormDataTitleReplacesName.php',
    ]);

    @unlink($old);
});

it('writes the diff’s own factual sentence as the description, not a TODO', function (): void {
    // The whole point of scaffolding. A consumer deciding whether the upgrade touches them needs this
    // sentence, and a placeholder would ship as one; what the author adds is the WHY.
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    $rename = (string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php');

    expect($rename)->toContain("description: '`FormData` publishes `title` where it published `name`.',")
        ->and($rename)->not->toContain('TODO')
        ->and((string) file_get_contents($this->changes.'/FormDataLostSubtotal.php'))
        ->toContain("description: '`FormData` no longer includes `subtotal`.',");

    @unlink($old);
});

it('fills a removal in completely, because the old artifact still states the shape', function (): void {
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataLostSubtotal.php'))
        ->toContain("#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer', description: 'The form total before tax, in cents.')]")
        ->and((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))
        // `to:` is the name the code spells today and `from:` the one older versions publish. Written the
        // other way round it renames the wrong end, which is the mistake the vocabulary invites.
        ->toContain("#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]");

    @unlink($old);
});

it('derives the older document from what it wrote, back to the artifact it was handed', function (): void {
    // The round trip, and the only assertion that proves the scaffold is worth anything: the classes go
    // in, the build reads them out, and the shape that comes back is the one the older version really
    // published — every property, its order, and `required`.
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    config()->set('docuccino.documents.v.info.version', '2026-06-01');

    expect(currentFormData())->toBe(publishedFormData());

    @unlink($old);
});

it('never overwrites a change class, and says which it left alone', function (): void {
    $old = publishedArtifact();
    file_put_contents($this->changes.'/FormDataLostSubtotal.php', '<?php // mine');

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('Left alone')
        ->expectsOutputToContain('FormDataLostSubtotal')
        ->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataLostSubtotal.php'))->toBe('<?php // mine')
        // And the ones it had no claim on are still written.
        ->and(scaffoldedFiles($this->changes))->toContain('FormDataTitleReplacesName.php');

    @unlink($old);
});

it('prefers a published stub over the packaged one, and says which it used', function (): void {
    $old = publishedArtifact();

    mkdir($this->root.'/stubs/docuccino', 0755, true);
    file_put_contents($this->root.'/stubs/docuccino/version-change.stub', <<<'STUB'
        <?php

        declare(strict_types=1);

        namespace {{ namespace }};

        {{ imports }}

        /** Written from the application's own stub. */
        #[ApiVersionChange(since: '{{ since }}', description: '{{ description }}')]
        {{ verbs }}
        final class {{ class }} {}

        STUB);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('from the published stub.')
        ->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))
        ->toContain("Written from the application's own stub.");

    @unlink($old);
});

it('uses the packaged stub when the application published none, and it still round-trips', function (): void {
    // The other half of the precedence, executed rather than asserted: no published file, so the
    // packaged template is what writes a class the build can read back.
    $old = publishedArtifact();

    expect(is_file($this->root.'/stubs/docuccino/version-change.stub'))->toBeFalse();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('from the packaged stub.')
        ->assertSuccessful();

    config()->set('docuccino.documents.v.info.version', '2026-06-01');

    expect(currentFormData()['properties'])->toHaveKey('name');

    @unlink($old);
});

it('writes byte-identical classes for identical inputs', function (): void {
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();
    $first = (string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php');

    foreach (scaffoldedFiles($this->changes) as $file) {
        unlink($this->changes.'/'.$file);
    }

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))->toBe($first)
        ->and($first)->not->toContain($this->root);

    @unlink($old);
});

it('writes nothing under --dry-run, and reports the same plan', function (): void {
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v', '--dry-run' => true])
        ->expectsOutputToContain('Would write')
        ->expectsOutputToContain('FormDataTitleReplacesName')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->changes))->toBe([]);

    @unlink($old);
});

it('reports the differences no verb declares rather than writing something wrong', function (): void {
    // A field this version ADDED, and an operation that is new: both real differences, neither
    // expressible. Saying so is the point — silence would read as "nothing changed there".
    $old = publishedArtifact();

    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((string) file_get_contents($old));
    $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
    $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
    $form = is_array($schemas['FormData'] ?? null) ? $schemas['FormData'] : [];
    $properties = is_array($form['properties'] ?? null) ? $form['properties'] : [];

    // `publishedAt` is in the head document and not in this one, so the head ADDED it.
    unset($properties['publishedAt']);
    $form['properties'] = $properties;
    $schemas['FormData'] = $form;
    $components['schemas'] = $schemas;
    $uir['components'] = $components;
    file_put_contents($old, (string) json_encode($uir));

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('Not declared')
        ->expectsOutputToContain('No verb declares a field a version ADDED')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->changes))->not->toContain('FormDataPublishedAtBecameRequired.php');

    @unlink($old);
});

it('reports the destination it chose and why, for every class it writes', function (): void {
    // A module inferred silently is the failure worth designing against: the class is discovered
    // wherever it lands, so a wrong module costs nothing until somebody extracts one — and by then the
    // history has gone with the other half. `FormData` lives in the workbench rather than in either
    // configured module, so this is the fallback saying so out loud.
    $old = publishedArtifact();
    mkdir($this->root.'/modules/Billing/Versions', 0755, true);

    config()->set('docuccino.documents.v.api_version.changes', ['changes', 'modules/*/Versions']);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('into changes — the first configured change directory; no configured module holds Workbench\\App\\Data\\FormData.')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->changes))->toContain('FormDataTitleReplacesName.php')
        ->and(scaffoldedFiles($this->root.'/modules/Billing/Versions'))->toBe([]);

    @unlink($old);
});

it('writes into the directory --in names, and refuses one that is not configured', function (): void {
    $old = publishedArtifact();
    mkdir($this->root.'/modules/Billing/Versions', 0755, true);

    config()->set('docuccino.documents.v.api_version.changes', ['changes', 'modules/*/Versions']);
    file_put_contents(
        $this->root.'/composer.json',
        (string) json_encode(['autoload' => ['psr-4' => [
            'Docuccino\\Scaffolded\\' => 'changes/',
            'Modules\\Billing\\' => 'modules/Billing/',
        ]]]),
    );

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v', '--in' => 'modules/Billing/Versions'])
        ->expectsOutputToContain('into modules/Billing/Versions — you named it with --in.')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->root.'/modules/Billing/Versions'))->toContain('FormDataTitleReplacesName.php')
        ->and(scaffoldedFiles($this->changes))->toBe([])
        // The namespace follows the directory's own PSR-4 prefix, not the first one configured.
        ->and((string) file_get_contents($this->root.'/modules/Billing/Versions/FormDataTitleReplacesName.php'))
        ->toContain('namespace Modules\\Billing\\Versions;');

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v', '--in' => 'nowhere'])
        ->expectsOutputToContain('--in=nowhere names none of this document\'s change directories')
        ->assertFailed();

    @unlink($old);
});

it('refuses a directory no PSR-4 prefix covers, because nothing would ever load the class', function (): void {
    $old = publishedArtifact();
    file_put_contents($this->root.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('No PSR-4 prefix in composer.json covers changes')
        ->assertFailed();

    expect(scaffoldedFiles($this->changes))->toBe([]);

    @unlink($old);
});

it('refuses a document that configures no changes directory', function (): void {
    $old = publishedArtifact();
    config()->set('docuccino.documents.v.api_version', []);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('configures no api_version.changes directory')
        ->assertFailed();

    @unlink($old);
});

it('scaffolds nothing from an artifact carrying no identities, and says why', function (): void {
    // Structural pairing cannot get from a published component to the class that produces it, so every
    // verb would be unwritable — and a scaffold that guessed the class would name somebody else's shape.
    $old = publishedArtifact();

    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((string) file_get_contents($old));
    file_put_contents($old, (string) json_encode(scaffoldStripIds($uir)));

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('carries no Docuccino identities')
        ->assertSuccessful();

    expect(scaffoldedFiles($this->changes))->toBe([]);

    @unlink($old);
});

it('says a removal it could not spell the shape of is the one thing left to fill in', function (): void {
    // The degraded answer, reported: the field goes back unconstrained — valid and vague — and the
    // author is told once, on the class it applies to, rather than left to notice.
    $old = publishedArtifact();

    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((string) file_get_contents($old));
    $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
    $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
    $form = is_array($schemas['FormData'] ?? null) ? $schemas['FormData'] : [];
    $properties = is_array($form['properties'] ?? null) ? $form['properties'] : [];
    $properties['legacy'] = ['type' => 'money'];
    $form['properties'] = $properties;
    $schemas['FormData'] = $form;
    $components['schemas'] = $schemas;
    $uir['components'] = $components;
    file_put_contents($old, (string) json_encode($uir));

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('the old artifact states no shape for `legacy`')
        ->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataLostLegacy.php'))->not->toContain('type:');

    @unlink($old);
});

it('refuses when it is disabled, when the document is unknown, and when the artifact is not there', function (): void {
    $old = publishedArtifact();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'nope'])
        ->expectsOutputToContain('Unknown document "nope".')
        ->assertFailed();

    $this->artisan('docuccino:version-changes', ['old' => $this->root.'/absent.json', 'document' => 'v'])
        ->expectsOutputToContain('Old artifact not found')
        ->assertFailed();

    config()->set('docuccino.enabled', false);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertFailed();

    expect(scaffoldedFiles($this->changes))->toBe([]);

    @unlink($old);
});

it('refuses a document with no version to scaffold against, unless --since names one', function (): void {
    $old = publishedArtifact();
    config()->set('docuccino.documents.v.info', ['title' => 'Forms API']);

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])
        ->expectsOutputToContain('states no version to scaffold against')
        ->assertFailed();

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v', '--since' => '2026-09-01'])
        ->assertSuccessful();

    expect((string) file_get_contents($this->changes.'/FormDataTitleReplacesName.php'))
        ->toContain("since: '2026-09-01',");

    @unlink($old);
});

it('refuses two documents minted by different identity algorithms', function (): void {
    // The differ will not pair across algorithm versions, and neither will this: a scaffold built on
    // nodes nobody paired would name a class for a schema that was never compared.
    $old = publishedArtifact();
    file_put_contents($old, str_replace(':v1:', ':v2:', (string) file_get_contents($old)));

    $this->artisan('docuccino:version-changes', ['old' => $old, 'document' => 'v'])->assertFailed();

    expect(scaffoldedFiles($this->changes))->toBe([]);

    @unlink($old);
});

/**
 * Every `x-docuccino` member out of a decoded document, at any depth.
 *
 * @param  array<array-key, mixed>  $node
 * @return array<array-key, mixed>
 */
function scaffoldStripIds(array $node): array
{
    unset($node['x-docuccino']);

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = scaffoldStripIds($value);
        }
    }

    return $node;
}
