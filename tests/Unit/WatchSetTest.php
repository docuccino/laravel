<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Tests\Support\WatchFixture;
use Docuccino\Laravel\Watch\WatchSet;

/**
 * What `docuccino:watch` watches. The load-bearing claim is that the per-operation half comes out of
 * the fragment store rather than out of a pattern, so these pin that a stored fragment's dependency
 * files are exactly what turns up in the set — and that the artifacts a build writes do not, which is
 * what stops a session rebuilding on its own output forever.
 */
beforeEach(function (): void {
    $this->fixture = WatchFixture::make();
    $this->watched = new WatchSet(
        app(DocumentBuilder::class),
        new FragmentStore(true, $this->fixture->path('fragments')),
        $this->fixture->root,
    );
});

afterEach(function (): void {
    $this->fixture->remove();
});

it('takes the per-operation half straight out of the stored fragments', function (): void {
    expect($this->watched->operationFiles())->toBe([
        $this->fixture->path('app/InvoiceController.php'),
        $this->fixture->path('docs/openapi.json'),
    ]);
});

it('reads no dependency out of an entry it cannot make sense of', function (): void {
    file_put_contents($this->fixture->path('fragments/broken.json'), 'not json');
    file_put_contents($this->fixture->path('fragments/shapeless.json'), '{"dependencies":[{"file":42},"nope"]}');

    expect($this->watched->operationFiles())->toBe([
        $this->fixture->path('app/InvoiceController.php'),
        $this->fixture->path('docs/openapi.json'),
    ]);
});

it('watches config, routes and the lock file whatever the fragments say', function (): void {
    expect($this->watched->documentRoots(['default']))->toContain(
        $this->fixture->path('config'),
        $this->fixture->path('routes'),
        $this->fixture->path('composer.json'),
        $this->fixture->path('composer.lock'),
    );
});

it('adds the content directory, the overlay files and the engine neon a document configures', function (): void {
    mkdir($this->fixture->path('content'), 0755, true);
    file_put_contents($this->fixture->path('overlay.yaml'), "overlay: 1.0.0\n");

    config()->set('docuccino.documents.default.content.dir', 'content');
    config()->set('docuccino.documents.default.overlays', ['overlay.yaml']);

    $watched = new WatchSet(
        app(DocumentBuilder::class),
        new FragmentStore(true, $this->fixture->path('fragments')),
        $this->fixture->root,
        ['neon' => 'phpstan.neon'],
    );

    expect($watched->documentRoots(['default']))->toContain(
        $this->fixture->path('content'),
        $this->fixture->path('overlay.yaml'),
        $this->fixture->path('phpstan.neon'),
    );
});

it('never watches an artifact the build writes', function (): void {
    config()->set('docuccino.documents.default.export.path', 'docs/openapi.json');

    expect($this->watched->roots(['default']))
        ->toContain($this->fixture->path('app/InvoiceController.php'))
        ->not->toContain($this->fixture->path('docs/openapi.json'));
});

it('stamps every file under a watched directory, and no dot entry', function (): void {
    mkdir($this->fixture->path('config/.hidden'), 0755, true);
    file_put_contents($this->fixture->path('config/.hidden/secret.php'), '<?php');
    file_put_contents($this->fixture->path('config/.env.php'), '<?php');

    $snapshot = $this->watched->snapshot([
        $this->fixture->path('config'),
        $this->fixture->path('composer.lock'),
        $this->fixture->path('nowhere'),
    ]);

    expect(array_keys($snapshot))->toBe([
        $this->fixture->path('composer.lock'),
        $this->fixture->path('config/docuccino.php'),
    ])->and($snapshot[$this->fixture->path('config/docuccino.php')])->toMatch('/^\d+:\d+$/');
});

it('reports an added, a removed and a rewritten file as changes, and nothing else', function (): void {
    $before = ['/a' => '1:1', '/b' => '1:1', '/c' => '1:1'];
    $after = ['/a' => '1:1', '/b' => '2:9', '/d' => '1:1'];

    expect(WatchSet::changed($before, $after))->toBe(['/b', '/c', '/d'])
        ->and(WatchSet::changed($before, $before))->toBe([]);
});

it('watches nothing for a configured path no filesystem call can accept', function (): void {
    // The second reader of the same overlay globs, and the one with no diagnostics channel of its own:
    // `glob()` raised here too, so `docuccino:watch` died on a config value the build had already
    // refused. Refusing once at the config boundary is what makes both readers safe from one place.
    config()->set('docuccino.documents.default.overlays', ["resources\0/overlays/*.yaml"]);
    config()->set('docuccino.documents.default.content.dir', "resources\0/docs");
    config()->set('docuccino.documents.default.webhooks.dir', "app\0/Webhooks");
    config()->set('docuccino.documents.default.api_version.changes.dir', "app\0/Api/Versions");

    $roots = $this->watched->documentRoots(['default']);

    expect($roots)->toContain($this->fixture->path('config'))
        ->and(array_filter($roots, static fn (string $root): bool => str_contains($root, "\0")))->toBe([])
        ->and($this->watched->snapshot($roots))->toBeArray();
});

it('adds the version-changes directory, so a change written mid-session registers', function (): void {
    mkdir($this->fixture->path('app/Api/Versions'), 0755, true);
    config()->set('docuccino.documents.default.api_version.changes.dir', 'app/Api/Versions');

    $roots = $this->watched->documentRoots(['default']);
    expect($roots)->toContain($this->fixture->path('app/Api/Versions'));

    $before = $this->watched->snapshot($roots);
    file_put_contents($this->fixture->path('app/Api/Versions/TotalReplacedAmount.php'), '<?php');

    expect(WatchSet::changed($before, $this->watched->snapshot($roots)))
        ->toBe([$this->fixture->path('app/Api/Versions/TotalReplacedAmount.php')]);
});

it('adds the webhook directory, and as a directory so a class created mid-session registers', function (): void {
    mkdir($this->fixture->path('app/Webhooks'), 0755, true);
    config()->set('docuccino.documents.default.webhooks.dir', 'app/Webhooks');

    $roots = $this->watched->documentRoots(['default']);
    expect($roots)->toContain($this->fixture->path('app/Webhooks'));

    // The point of rooting on the directory: a class with no fragment behind it yet is invisible to
    // the operation half, so nothing else in the set would move when someone writes one.
    $before = $this->watched->snapshot($roots);
    file_put_contents($this->fixture->path('app/Webhooks/InvoicePaid.php'), '<?php');

    expect(WatchSet::changed($before, $this->watched->snapshot($roots)))
        ->toBe([$this->fixture->path('app/Webhooks/InvoicePaid.php')]);
});
